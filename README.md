# Filecache

This module provides the ability to store caches in files.

Using opcache is highly recommended. This way all cache files will get
stored in opcache memory. It will increase filecache speed dramatically.

Right now the module provides only a class to replace BackdropDatabaseCache. No
need to enable the module. Just download it into modules folder and update
settings.php.

## Installation

1. Download module to modules folder.
2. Change settings.php by adding next lines:

```php
$settings['cache_default_class'] = 'FilecacheCache';
$settings['cache_backends'] = array('modules/filecache/filecache.class.php');
```

## License

This project is GPL v3 software. See the LICENSE.txt file in this directory for
complete text.

## Maintainters

* [Herb v/d Dool](https://github.com/herbdool)

## Credits

Created by [Gor Martsen](https://github.com/Gormartsen).

This module has been inspired by <http://drupal.org/project/filecache> but it is
not Drupal 7 port. All code has been written based on cache.inc file and
Backdrop API.
