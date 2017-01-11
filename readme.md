# JYmusic/assets


一个php静态资源管理工具. [https://github.com/jymusic/assets](https://github.com/jymusic/assets)
由于强迫症原因修改[mrclay/minify](https://github.com/mrclay/minify)资源管理工具，添加命名空间

### 简单调用

```php
use JYmusic\Assets\Minify;

//合并压缩js
$options = [
   'files' => [
       '//js/test.js',
       '//js/test2.js',
       '//js/test3.js'
    ]
];
Minify::serve("Files", $options);

//合并压缩css
$options = [
   'files' => [
       '//js/test.css',
       '//js/test2.css',
       '//js/test3.css'
    ]
];
Minify::serve("Files", $options);


```

## 安装

### 使用composer

```
$ composer require jymusic/assets

```

```json
{
    "require": {
        "jymusic/assets"  : "~0.1.0"
    }
}
```

```php
<?php
require 'vendor/autoload.php';

use JYmusic\Assets\Minify;

Minify::serve("Files", ['files' => ["//js/test.js"]]);

Minify::serve("Files", ['files' => ["//js/test.css"]]);
```

