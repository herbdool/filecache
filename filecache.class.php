<?php

/**
 * @file
 * FilecacheCache class
 */

/**
 * Max filename length for cid.
 *
 * Doesn't include cache bin prefix. Must be at least 34 (see
 * FILECACHE_CID_FILENAME_POS_BEFORE_MD5).
 */
define('FILECACHE_CID_FILENAME_MAX', 200);

/**
 * Cut point between not MD5 encoded and MD5 encoded.
 *
 * 34 is '%_' + 32 hexdigits for 128-bit MD5
 */
define('FILECACHE_CID_FILENAME_POS_BEFORE_MD5', FILECACHE_CID_FILENAME_MAX - 34);

/**
 * Defines a Filecache base cache class.
 *
 * Use Directory as a bin and file as a cache item.
 */
abstract class FilecacheBaseCache implements BackdropCacheInterface {

  /**
   * Location of all cache files.
   *
   * @var string|null
   */
  protected static $file_storage_directory;

  /**
   * The cache bin where the cache object is stored.
   *
   * @var string
   */
  protected $bin;

  /**
   * File cache directory
   *
   * @var string
   */
  protected $directory;

  /**
   * Constructs a new FilecacheBaseCache object.
   */
  public function __construct($bin) {
    // All cache bins should be prefixed with 'cache_', except for the
    // default 'cache' bin.
    if ($bin != 'cache') {
      $bin = 'cache_' . $bin;
    }
    $this->bin = $bin;

    $this->prepareDirectory($bin);
  }

  /**
   * File storage directory
   *
   * @return string
   *   The main file storage directory.
   */
  protected static function fileStorageDirectory() {
    if (empty(self::$file_storage_directory)) {
      // If private path exists, store it there, fallback to public files.
      $private_path = config_get('system.core', 'file_private_path');
      $public_path = config_get('system.core', 'file_public_path');
      $default_location = 'files';
      if ($private_path) {
        $default_location = realpath($private_path);
      }
      if (empty($default_location)) {
        $default_location = realpath($public_path);
      }

      self::$file_storage_directory = settings_get('filecache_storage_dir', $default_location . '/filecache');

      if (!function_exists('file_prepare_directory')) {
        require_once BACKDROP_ROOT . '/core/includes/file.inc';
      }

      if (!is_dir(self::$file_storage_directory) && !file_exists(self::$file_storage_directory)) {
        file_prepare_directory(self::$file_storage_directory, FILE_CREATE_DIRECTORY);
      }

      // Add htaccess private settings if it's not in the private path.
      if ((self::$file_storage_directory != config_get('system.core', 'file_private_path') . '/filecache') && backdrop_is_apache()) {
        file_save_htaccess(self::$file_storage_directory, TRUE);
      }
    }

    return self::$file_storage_directory;
  }

  /**
   * Prepare the directory
   */
  protected function prepareDirectory() {
    $dir = self::fileStorageDirectory();
    $this->directory = $dir . '/' . $this->bin;


    if (!function_exists('file_prepare_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }

    if (!is_dir($this->directory) && !file_exists($this->directory)) {
      file_prepare_directory($this->directory, FILE_CREATE_DIRECTORY);
    }
  }

  /**
   * Prepares a cached item.
   *
   * Checks that items are either permanent or did not expire, and unserializes
   * data as appropriate.
   *
   * @param string $cache
   *   An item loaded from BackdropCacheInterface::get().
   *
   * @return object
   *   The item with data unserialized as appropriate or FALSE if there is no
   *   valid item to load.
   */
  abstract protected function prepareItem($cache);

  /**
   * Normalizes a cache ID so it is usable for file name.
   *
   * @param string $cid
   *   Cache ID.
   * @return string
   *   String that is derived from $cid and can be used as file name.
   */
  protected function prepareCid(string $cid): string {
    $safe_cid = $this->safeCid($cid);
    if (strlen($safe_cid) > FILECACHE_CID_FILENAME_MAX) {
      $safe_cid =
        substr($safe_cid, 0, FILECACHE_CID_FILENAME_POS_BEFORE_MD5) .
        ',' .
        md5(substr($safe_cid, FILECACHE_CID_FILENAME_POS_BEFORE_MD5));
    }

    return $safe_cid;
  }

  /**
   * Create a sub directory
   *   Uses up to three colons or slashes, as a sub directory. This will help
   *   split up some bins into smaller sub directories.
   *
   * @param string $cid
   *   Cache ID. Needs to be the safe cid where colon is encoded.
   * @return string
   *   The path to the sub directory.
   */
  protected function prepareSubDirectory(string $cid): string {
    $hash = md5($cid);

    $directory = $this->directory . '/' . $hash[0] . $hash[1];

    if (!function_exists('file_prepare_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }

    if (!is_dir($directory) && !file_exists($directory)) {
      file_prepare_directory($directory, FILE_CREATE_DIRECTORY);
    }

    return $directory;
  }

  /**
   * Safe cache ID
   *
   * @param string $cid
   *   Cache ID.
   * @return string
   */
  protected function safeCid(string $cid): string {
    // Use urlencode(), but turn the
    // encoded ':' and '/' back into ordinary characters since they're used so
    // often. (Especially ':', but '/' is used in cache_menu.)
    // We can't turn them back into their own characters though; both are
    // considered unsafe in filenames. So turn ':' -> '@' and '/' -> '='
    return str_replace(array('%3A', '%2F'), array('@', '='), urlencode($cid));
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array &$cids) {
    try {
      $cache = array();
      foreach ($cids as $cid) {
        if ($item = $this->get($cid)){
          $cache[$cid] = $item;
        }
      }
      $cids = array_diff($cids, array_keys($cache));
      return $cache;
    }
    catch (Exception $e) {
      // If the Filecache is not available, cache requests should
      // return an empty array in order to allow exception handling to occur.
      return array();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    // Entity cache passes in an array instead of a single ID.
    // See https://github.com/backdrop/backdrop-issues/issues/2158
    // @todo Remove this when fixed in core.
    $cids = $cid;
    if (!is_array($cids)) {
      $cids = array($cid);
    }
    $this->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function deletePrefix($prefix) {
    if (!function_exists('file_scan_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }

    $safe_prefix = $this->prepareCid($prefix);
    $files = file_scan_directory($this->directory, '/^' . preg_quote($safe_prefix, '/') . '.*/');
    foreach ($files as $file) {
      if (is_file($file->uri)) {
        @backdrop_unlink($file->uri);
        clearstatcache(FALSE, $file->uri);
        $this->removeEmptySubDirectory($file->uri);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function flush() {
    if (!function_exists('file_prepare_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }

    @file_unmanaged_delete_recursive($this->directory);

    file_prepare_directory($this->directory, FILE_CREATE_DIRECTORY);
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    if (!is_dir($this->directory)){
      return;
    }

    // Remove expired items in the cache bin.
    if (!function_exists('file_scan_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }
    $expire_files = file_scan_directory($this->directory, '/*.expire$/');
    foreach ($expire_files as $file) {
      $timestamp = file_get_contents($file->uri);
      if ($timestamp < REQUEST_TIME) {
        if (@backdrop_unlink($file->uri)) {
          clearstatcache(FALSE, $file->uri);
        }
        $cache_path = substr($file->uri, 0, -7);
        if (@backdrop_unlink($cache_path)) {
          clearstatcache(FALSE, $cache_path);
        }
        $this->removeEmptySubDirectory($file->uri);
      }
    }

  }

  /**
   * Remove empty sub-directory.
   *   This avoids removing the cache bin since it could have a large number
   *   of directory items to check.
   *
   * @param string $filepath
   */
  protected function removeEmptySubDirectory($filepath): void {
    // Remove subdirectory if empty.
    $parent_directory = dirname($filepath);
    if ($parent_directory != $this->directory) {
      $handle = opendir($parent_directory);
      $empty = TRUE;
      while (FALSE !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != "..") {
          $empty = FALSE;
          break;
        }
      }
      closedir($handle);

      if ($empty) {
        @backdrop_rmdir($parent_directory);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $this->garbageCollection();

    $handle = opendir($this->directory);
    $empty = TRUE;
    while (FALSE !== ($entry = readdir($handle))) {
      if ($entry != "." && $entry != "..") {
        $empty = FALSE;
        break;
      }
    }
    closedir($handle);
    return $empty;
  }
}

/**
 * Defines a Filecache cache implementation.
 *
 * Store cache as serialized objects in files.
 */
class FilecacheCache extends FilecacheBaseCache {

  /**
   * Get file path
   *
   * @param string $cid
   *
   * @return string
   *   File path.
   */
  protected function getFilePath(string $cid): string {
    $cid = $this->prepareCid($cid);
    return $this->prepareSubDirectory($cid) . '/' . $cid;
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid) {
    $filepath = $this->getFilePath($cid);
    if (file_exists($filepath)) {
      $cache = $this->getContents($filepath);
      if (!empty($cache)) {
        return $this->prepareItem($cache);
      }
      return FALSE;
    }
    return FALSE;
  }

  /**
   * Get file contents
   *
   * @param string $path
   *
   * @return string
   */
  protected function getContents($path): string {
    $contents = '';

    $handle = fopen($path, 'rb');
    if ($handle) {
      try {
        if (flock($handle, LOCK_SH)) {
          clearstatcache(TRUE, $path);
          $contents = fread($handle, filesize($path) ?: 1);
          flock($handle, LOCK_UN);
        }
      } finally {
        fclose($handle);
      }
    }

    return $contents;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareItem($cache) {
    if (!$item = @unserialize($cache)){
      return FALSE;
    }
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = CACHE_PERMANENT) {
    $filepath = $this->getFilePath($cid);
    $cid = $this->prepareCid($cid);
    $cache = new StdClass;
    $cache->cid = $cid;
    $cache->created = REQUEST_TIME;
    $cache->expire = $expire;
    $cache->data = $data;
    try {
      $cache = serialize($cache);

      file_put_contents($filepath, $cache, LOCK_EX);
      backdrop_chmod($filepath);
      if ($expire !== CACHE_PERMANENT) {
        file_put_contents($filepath . '.expire', $expire, LOCK_EX);
        backdrop_chmod($filepath . '.expire');
      }
    }
    catch (Exception $e) {
      // The Filecache may not be available, so we'll ignore these calls.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    foreach ($cids as $cid) {
      $filepath = $this->getFilePath($cid);
      if (is_file($filepath)) {
        @backdrop_unlink($filepath);
        clearstatcache(FALSE, $filepath);
      }
      if (is_file($filepath . '.expire')) {
        @backdrop_unlink($filepath . '.expire');
        clearstatcache(FALSE, $filepath . '.expire');
      }
    }
  }
}

/**
 * Defines a Filecache cache as PHP implementation.
 *
 * Store and include cache items as PHP files. The cache is serialized, encoded
 * with base64 and assigned to a variable in the file. This allows the files to
 * be cached in opcode. However, they're more likely to cause a fatal error if
 * the file is corrupted so this is considered experimental.
 *
 * The serialized object also needs to have base64 encoding. The cache_page bin
 * can include gzip compressed page bodies that could include characters such as
 * single quotes which cannot be escaped for storing in a variable.
 */
class FilecachePhpCache extends FilecacheBaseCache {

  /**
   * Get file path
   *
   * @param string $cid
   *
   * @return string
   *   File path.
   */
  protected function getFilePath(string $cid): string {
    $cid = $this->prepareCid($cid);
    return $this->prepareSubDirectory($cid) . '/' . $cid . '.php';
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid) {
    $filepath = $this->getFilePath($cid);
    if (file_exists($filepath)) {
      include $filepath;
      if (isset($cache)) {
        $item = $this->prepareItem($cache);
        if (!$item) {
          return FALSE;
        }
        return $item;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareItem($cache) {
    if (!$item = @unserialize(@base64_decode($cache))){
      return FALSE;
    }
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = CACHE_PERMANENT) {
    $filepath = $this->getFilePath($cid);
    $cid = $this->prepareCid($cid);
    $cache = new StdClass;
    $cache->cid = $cid;
    $cache->created = REQUEST_TIME;
    $cache->expire = $expire;
    $cache->data = $data;
    try {
      $cache = '<?php $cache=\'' . base64_encode(serialize($cache)) . '\';';

      file_put_contents($filepath, $cache, LOCK_EX);
      backdrop_chmod($filepath);
      if ($expire !== CACHE_PERMANENT) {
        file_put_contents($filepath . '.expire', $expire, LOCK_EX);
        backdrop_chmod($filepath . '.expire');
      }
    }
    catch (Exception $e) {
      // The Filecache may not be available, so we'll ignore these calls.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    foreach ($cids as $cid) {
      $filepath = $this->getFilePath($cid);
      $cid = $this->prepareCid($cid);
      if (is_file($filepath)) {
        @backdrop_unlink($filepath);
        clearstatcache(FALSE, $filepath);
      }
      if (is_file($filepath . '.expire')) {
        @backdrop_unlink($filepath . '.expire');
        clearstatcache(FALSE, $filepath . '.expire');
      }
    }
  }
}
