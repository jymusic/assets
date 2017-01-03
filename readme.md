# JYmusic/assets


一个php静态资源管理工具. [https://github.com/jymusic/assets](https://github.com/jymusic/assets)
由于强迫症原因修改[mrclay/minify](https://github.com/mrclay/minify)资源管理工具，添加命名空间

### 简单调用

```php
use JYmusic\Assets\Minify;

//合并压缩js
$files = [
   '//js/test.js',
   '//js/test2.js',
   '//js/test3.js'
];

Minify::serve("Files", $files);

//合并压缩css

$files = [
   '//css/test.css',
   '//css/test2.css',
   '//css/test3.css'
];

Minify::serve("Files", $files);


```

## Installation

### 使用composer

```
$ composer require "jymusic/assets"  "dev-master"

```

```json
{
    "require": {
        "jymusic/assets"  : "dev-master"
    }
}
```

```php
<?php
require 'vendor/autoload.php';

use JYmusic\Assets\Minify;

Minify::serve("Files","//js/test.js");

Minify::serve("Files", "//css/test.css");
```

