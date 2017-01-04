<?php
/**
 * Class Minify  
 * @package Minify
 */

namespace JYmusic\Assets;

use JYmusic\Assets\HTTP\Encoder;
use JYmusic\Assets\Minify\Source;
use JYmusic\Assets\Minify\Cache\File;
use JYmusic\Assets\HTTP\ConditionalGet;


/**
 * Minify - Combines, minifies, and caches JavaScript and CSS files on demand.
 * Requires PHP 5.1.0.
 *
 * @package Minify
 * @author Ryan Grove <ryan@wonko.com>
 * @author Stephen Clay <steve@mrclay.org>
 *
 */
class Minify {
    
    /**
     * 常量定义
     */
    const VERSION   = '2.2.0';
    const TYPE_CSS  = 'text/css';
    const TYPE_HTML = 'text/html';
    const TYPE_JS   = 'application/x-javascript';
    const URL_DEBUG = 'http://code.google.com/p/minify/wiki/Debugging';
    
    /**
     * How many hours behind are the file modification times of uploaded files?
     * 
     * If you upload files from Windows to a non-Windows server,
     * Windows may report
     * incorrect mtimes for the files. Immediately after modifying and uploading a
     * file, use the touch command to update the mtime on the server. If the mtime 
     * jumps ahead by a number of hours, set this variable to that number. If the mtime 
     * moves back, this should not be needed.
     *
     * @var int $uploaderHoursBehind
     */
    public static $uploaderHoursBehind = 0;
    
    /**
     * If this string is not empty AND the serve() option 'bubbleCssImports' is
     * NOT set, then serve() will check CSS files for @import declarations that
     * appear too late in the combined stylesheet. If found, serve() will prepend
     * the output with this warning.
     *
     * @var string $importWarning
     */
    public static $importWarning = "/* See http://code.google.com/p/minify/wiki/CommonProblems#@imports_can_appear_in_invalid_locations_in_combined_CSS_files */\n";

    /**
     * DOCUMENT_ROOT 是否被设置
     * 
     * @var bool
     */
    public static $isDocRootSet = false;
    
    /**
     * 所有 Minify\Cache\* 对象 or null (i.e. 不使用服务器缓存)
     *
     * @var Minify\Cache\File
     */
    private static $_cache = null;
    
    /**
     * 初始化控制器
     *
     * @var Minify\Controller\Base
     */
    protected static $_controller = null;
    
    /**
     * 初始化当前请求的选项
     *
     * @var array
     */
    protected static $_options = null;
    
    
    /**
     * 指定一个缓存对象(与Minify\Cache\File 相同的缓存接口 ) 或者直接使用 Minify\Cache\File.
     * 
     * If not called, Minify 不会使用缓存, 为每个200响应, 将需要重组文件、压缩和编码输出.
     *
     * @param mixed $cache 与Minify\Cache\File 相同的缓存接口或一个目录路径, 或null禁用缓存. (default = '')
     * 
     * @param bool $fileLocking (default = true)
     *
     * @return null
     */
    public static function setCache($cache = '', $fileLocking = true)
    {
        if (is_string($cache)) {
            self::$_cache = new File($cache, $fileLocking);
        } else {
            self::$_cache = $cache;
        }
    }
    
    /**
     * 执行合并压缩任务 
     *
     * @param mixed $controller 控制器实例化或者直接控制器名称
     * 
     * @param array $options    控制所需参数
     * 
     * @return null|array 
     *
     * @throws Exception
     */
    public static function serve($controller, $options = array())
    {
        if (! self::$isDocRootSet && 0 === stripos(PHP_OS, 'win')) {
            self::setDocRoot();
        }

        if (is_string($controller)) {
            //创建控制器实例
            $class      = '\\JYmusic\\Assets\\Minify\\Controller\\' . $controller;        
            $controller = new $class();
        }
        
        $options = $controller->setupSources($options);
        $options = $controller->analyzeSources($options);
        self::$_options = $controller->mixInDefaultOptions($options);
        
        // 检查请求的有效性
        if (! $controller->sources) {
            // 无效请求 !
            if (! self::$_options['quiet']) {
                self::_errorExit(self::$_options['badRequestHeader'], self::URL_DEBUG);
            } else {
                list(,$statusCode) = explode(' ', self::$_options['badRequestHeader']);
                return array(
                    'success' => false
                    ,'statusCode' => (int)$statusCode
                    ,'content' => ''
                    ,'headers' => array()
                );
            }
        }
        
        self::$_controller = $controller;
        
        if (self::$_options['debug']) {
            self::_setupDebug($controller->sources);
            self::$_options['maxAge'] = 0;
        }
        
        // 确定编码
        if (self::$_options['encodeOutput']) {
            $sendVary = true;
            if (self::$_options['encodeMethod'] !== null) {
                // 控制器具体要求
                $contentEncoding = self::$_options['encodeMethod'];
            } else {
                //$contentEncoding 可能'x-gzip' 而我们的内部encodeMethod 是 'gzip'.   
                list(self::$_options['encodeMethod'], $contentEncoding) = Encoder::getAcceptedEncoding(false, false);
                $sendVary = ! Encoder::isBuggyIe();
            }
        } else {
            self::$_options['encodeMethod'] = ''; 
        }
        
        // 检查客户端缓存
        $cgOptions = array(
            'lastModifiedTime' => self::$_options['lastModifiedTime']
            ,'isPublic' => self::$_options['isPublic']
            ,'encoding' => self::$_options['encodeMethod']
        );
        
        if (self::$_options['maxAge'] > 0) {
            $cgOptions['maxAge'] = self::$_options['maxAge'];
        } elseif (self::$_options['debug']) {
            $cgOptions['invalidate'] = true;
        }
       
        $cg = new ConditionalGet($cgOptions);

        if ($cg->cacheIsValid) {
            
            // 客户端缓存有效
            if (! self::$_options['quiet']) {
                $cg->sendHeaders();
                exit();
                //return;
            } else {
                return array(
                    'success' => true
                    ,'statusCode' => 304
                    ,'content' => ''
                    ,'headers' => $cg->getHeaders()
                );
            }
        } else {
            // 客户端需要输出
            $headers = $cg->getHeaders();
            unset($cg);
        }
        
        if (self::$_options['concatOnly']) {
            foreach ($controller->sources as $key => $source) {
                if (self::$_options['contentType'] === self::TYPE_JS) {
                    $source->minifier = "";
                } elseif (self::$_options['contentType'] === self::TYPE_CSS) {
                    $source->minifier = array('Minify\CSS', 'minify');
                    $source->minifyOptions['compress'] = false;
                }
            }
        }
       
        if (self::$_options['contentType'] === self::TYPE_CSS && self::$_options['rewriteCssUris']) {
            foreach ($controller->sources as $key => $source) {
                if ($source->filepath
                    && !isset($source->minifyOptions['currentDir'])
                    && !isset($source->minifyOptions['prependRelativePath'])
                ) {
                    $source->minifyOptions['currentDir'] = dirname($source->filepath);
                }
            }
        }
        
        // 检查服务器缓存
        if (null !== self::$_cache && ! self::$_options['debug']) {

            // 使用缓存
            $cacheId = self::_getCacheId();
            $fullCacheId = (self::$_options['encodeMethod'])
                ? $cacheId . '.gz'
                : $cacheId;
            // 检查有效的缓存条目
            $cacheIsReady = self::$_cache->isValid($fullCacheId, self::$_options['lastModifiedTime']); 
            if ($cacheIsReady) {
                $cacheContentLength = self::$_cache->getSize($fullCacheId);    
            } else {
                // 生成 & cache content
                try {
                    $content = self::_combineMinify();
                } catch (Exception $e) {
                    self::$_controller->log($e->getMessage());
                    if (! self::$_options['quiet']) {
                        self::_errorExit(self::$_options['errorHeader'], self::URL_DEBUG);
                    }
                    throw $e;
                }
                self::$_cache->store($cacheId, $content);
                if (function_exists('gzencode') && self::$_options['encodeMethod']) {
                    self::$_cache->store($cacheId . '.gz', gzencode($content, self::$_options['encodeLevel']));
                }
            }
        } else {
            // 没有缓存
            $cacheIsReady = false;
            try {
                $content = self::_combineMinify();
            } catch (Exception $e) {
                self::$_controller->log($e->getMessage());
                if (! self::$_options['quiet']) {
                    self::_errorExit(self::$_options['errorHeader'], self::URL_DEBUG);
                }
                throw $e;
            }
        }
        if (! $cacheIsReady && self::$_options['encodeMethod']) {
            //需要编码
            $content = gzencode($content, self::$_options['encodeLevel']);
        }
        
        // 添加 headers 头信息
        $headers['Content-Length'] = $cacheIsReady
            ? $cacheContentLength
            : ((function_exists('mb_strlen') && ((int)ini_get('mbstring.func_overload') & 2))
                ? mb_strlen($content, '8bit')
                : strlen($content)
            );
        $headers['Content-Type'] = self::$_options['contentTypeCharset']
            ? self::$_options['contentType'] . '; charset=' . self::$_options['contentTypeCharset']
            : self::$_options['contentType'];
        if (self::$_options['encodeMethod'] !== '') {
            $headers['Content-Encoding'] = $contentEncoding;
        }
        if (self::$_options['encodeOutput'] && $sendVary) {
            $headers['Vary'] = 'Accept-Encoding';
        }

        if (! self::$_options['quiet']) {
            // 输出 headers & content
            foreach ($headers as $name => $val) {
                header($name . ': ' . $val);
            }
            if ($cacheIsReady) {
                self::$_cache->display($fullCacheId);
            } else {
                echo $content;
            }
        } else {
            return array(
                'success' => true
                ,'statusCode' => 200
                ,'content' => $cacheIsReady
                    ? self::$_cache->fetch($fullCacheId)
                    : $content
                ,'headers' => $headers
            );
        }
    }
    
    /**
     * 返回组合为一组压缩内容来源
     *
     * 没有内部缓存和内容将不会被使用HTTP进行编码。
     * 
     * @param array $sources filepaths 和，或者 Minif\Source 对象
     * 
     * @param array $options (optional) array of options for serve. By default
     * these are already set: quiet = true, encodeMethod = '', lastModifiedTime = 0.
     * 
     * @return string
     */
    public static function combine($sources, $options = array())
    {
        $cache = self::$_cache;
        self::$_cache = null;
        $options = array_merge(array(
            'files' => (array)$sources
            ,'quiet' => true
            ,'encodeMethod' => ''
            ,'lastModifiedTime' => 0
        ), $options);
        $out = self::serve('Files', $options);
        self::$_cache = $cache;
        return $out['content'];
    }
    
    /**
     * 设置 $_SERVER['DOCUMENT_ROOT']. 在IIS这个值创建来自 SCRIPT_FILENAME 和 SCRIPT_NAME.
     * 
     * @param string $docRoot 使用 DOCUMENT_ROOT
     */
    public static function setDocRoot($docRoot = '')
    {
        self::$isDocRootSet = true;
        if ($docRoot) {
            $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        } elseif (isset($_SERVER['SERVER_SOFTWARE'])
                  && 0 === strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS/')) {
            $_SERVER['DOCUMENT_ROOT'] = substr(
                $_SERVER['SCRIPT_FILENAME']
                ,0
                ,strlen($_SERVER['SCRIPT_FILENAME']) - strlen($_SERVER['SCRIPT_NAME']));
            $_SERVER['DOCUMENT_ROOT'] = rtrim($_SERVER['DOCUMENT_ROOT'], '\\');
        }
    }
    

    /**
     * @param string $header
     *
     * @param string $url
     */
    protected static function _errorExit($header, $url)
    {
        $url = htmlspecialchars($url, ENT_QUOTES);
        list(,$h1) = explode(' ', $header, 2);
        $h1 = htmlspecialchars($h1);
        // FastCGI environments require 3rd arg to header() to be set
        list(, $code) = explode(' ', $header, 3);
        header($header, true, $code);
        header('Content-Type: text/html; charset=utf-8');
        echo "<h1>$h1</h1>";
        echo "<p>Please see <a href='$url'>$url</a>.</p>";
        exit;
    }

    /**
     * 使用Minify\Lines设置源
     *
     * @param Minify_Source[] $sources Minify\Source 实例
     */
    protected static function _setupDebug($sources)
    {
        foreach ($sources as $source) {
            $source->minifier = array('Minify\Lines', 'minify');
            $id = $source->getId();
            $source->minifyOptions = array(
                'id' => (is_file($id) ? basename($id) : $id)
            );
        }
    }
    
    /**
     * 合并压缩资源
     *
     * @return string
     *
     * @throws Exception
     */
    protected static function _combineMinify()
    {
        $type = self::$_options['contentType']; 
        
        // 在合并脚本时,确保所有语句分离和后一行注释结束
        $implodeSeparator = ($type === self::TYPE_JS)
            ? "\n;"
            : '';

        $defaultOptions = isset(self::$_options['minifierOptions'][$type])
            ? self::$_options['minifierOptions'][$type]
            : array();

        $defaultMinifier = isset(self::$_options['minifiers'][$type])
            ? self::$_options['minifiers'][$type]
            : false;

        $content = array();
        $i = 0;
        $l = count(self::$_controller->sources);
        $groupToProcessTogether = array();
        $lastMinifier = null;
        $lastOptions = null;
        do {
            // get next source
            $source = null;
            if ($i < $l) {
                $source = self::$_controller->sources[$i];               
                /* @var Minify\Source $source */
                $sourceContent = $source->getContent();
                // allow the source to override our minifier and options
                $minifier = (null !== $source->minifier)
                    ? $source->minifier
                    : $defaultMinifier;
               
                $minifier[0]    = '\\JYmusic\\Assets\\' . $minifier[0];
                
                $options = (null !== $source->minifyOptions)
                    ? array_merge($defaultOptions, $source->minifyOptions)
                    : $defaultOptions;
            }
            
            // do we need to process our group right now?
            if ($i > 0                               // yes, we have at least the first group populated
                && (
                    ! $source                        // yes, we ran out of sources
                    || $type === self::TYPE_CSS      // yes, to process CSS individually (avoiding PCRE bugs/limits)
                    || $minifier !== $lastMinifier   // yes, minifier changed
                    || $options !== $lastOptions)    // yes, options changed
                )
            {
                // minify previous sources with last settings
                
                $imploded = implode($implodeSeparator, $groupToProcessTogether);
               
                $groupToProcessTogether = array();
                if ($lastMinifier) {
                    try {
                        $content[] = call_user_func($lastMinifier, $imploded, $lastOptions);
                    } catch (\Exception $e) {
                        throw new \Exception("Exception in minifier: " . $e->getMessage());
                    }
                } else {
                    $content[] = $imploded;
                }
            }
            // add content to the group
            
            if ($source) {
                $groupToProcessTogether[] = $sourceContent;
                $lastMinifier = $minifier;
                $lastOptions = $options;
            }
            $i++;
        } while ($source);

        $content = implode($implodeSeparator, $content);
        
        if ($type === self::TYPE_CSS && false !== strpos($content, '@import')) {
            $content = self::_handleCssImports($content);
        }
        
        // do any post-processing (esp. for editing build URIs)
        if (self::$_options['postprocessorRequire']) {
            require_once self::$_options['postprocessorRequire'];
        }
        if (self::$_options['postprocessor']) {
            $content = call_user_func(self::$_options['postprocessor'], $content, $type);
        }
        return $content;
    }
    
    /**
     * 让这个请求缓存一个唯一的id
     *
     * @param string $prefix
     *
     * @return string
     */
    protected static function _getCacheId($prefix = 'minify')
    {
        $name = preg_replace('/[^a-zA-Z0-9\\.=_,]/', '', self::$_controller->selectionId);
        $name = preg_replace('/\\.+/', '.', $name);
        $name = substr($name, 0, 100 - 34 - strlen($prefix));
        $md5 = md5(serialize(array(
            Source::getDigest(self::$_controller->sources)
            ,self::$_options['minifiers'] 
            ,self::$_options['minifierOptions']
            ,self::$_options['postprocessor']
            ,self::$_options['bubbleCssImports']
            ,self::VERSION
        )));
        return "{$prefix}_{$name}_{$md5}";
    }
    
    /**
     * Bubble CSS @imports to the top or prepend a warning if an import is detected not at the top.
     *
     * @param string $css
     *
     * @return string
     */
    protected static function _handleCssImports($css)
    {
        if (self::$_options['bubbleCssImports']) {
            // bubble CSS imports
            preg_match_all('/@import.*?;/', $css, $imports);
            $css = implode('', $imports[0]) . preg_replace('/@import.*?;/', '', $css);
        } else if ('' !== self::$importWarning) {
            // remove comments so we don't mistake { in a comment as a block
            $noCommentCss = preg_replace('@/\\*[\\s\\S]*?\\*/@', '', $css);
            $lastImportPos = strrpos($noCommentCss, '@import');
            $firstBlockPos = strpos($noCommentCss, '{');
            if (false !== $lastImportPos
                && false !== $firstBlockPos
                && $firstBlockPos < $lastImportPos
            ) {
                // { appears before @import : prepend warning
                $css = self::$importWarning . $css;
            }
        }
        return $css;
    }
}
