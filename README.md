# Filecache

This module provides the ability to store caches in files.

The default stores cache as serialized objects as strings in files.

There is also an experimental approach that stores cache files as PHP code so
that if opcache is installed, that it has an opportunity of storing the
cache-as-code in opcache memory. This will likely increase retrieval of the
cache speed. The risk, however, is that if a cache is corrupted that it could
result in problems loading the site, whereas the default approach will just
rebuild the cache item.

## Installation

1. Download module to modules folder.
2. No need to enable the module. Right now the module only provides only a class
   to replace BackdropDatabaseCache.
3. Change `settings.php` by adding the next lines:

```php
$settings['cache_default_class'] = 'FilecacheCache';
$settings['cache_backends'] = array('modules/filecache/filecache.class.php');
```

or, the experimental approach of storing the cache in PHP files:

```php
$settings['cache_default_class'] = 'FilecachePhpCache';
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
