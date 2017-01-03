<?php

namespace JYmusic\Assets\Minify;

/**
 * Detect whether request should be debugged
 *
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 */
class DebugDetector {
    public static function shouldDebugRequest($cookie, $get, $requestUri)
    {
        if (isset($get['debug'])) {
            return true;
        }
        if (! empty($cookie['minifyDebug'])) {
            foreach (preg_split('/\\s+/', $cookie['minifyDebug']) as $debugUri) {
                $pattern = '@' . preg_quote($debugUri, '@') . '@i';
                $pattern = str_replace(array('\\*', '\\?'), array('.*', '.'), $pattern);
                if (preg_match($pattern, $requestUri)) {
                    return true;
                }
            }
        }
        return false;
    }
}
