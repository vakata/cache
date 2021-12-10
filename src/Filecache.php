<?php

namespace vakata\cache;

class Filecache implements CacheInterface
{
    protected $dir;
    protected $namespace = 'default';

    /**
     * Create an instance
     * @param  string      $dir              the path to the directory where the cache files will be stored
     * @param  string      $defaultNamespace the default namespace to store in (namespaces are collections that can be easily cleared in bulk)
     */
    public function __construct($dir, $defaultNamespace = 'default')
    {
        $this->dir = @realpath($dir);
        if (!$this->dir) {
            throw new CacheException('Invalid cache dir');
        }
        $this->namespace = $defaultNamespace;
    }

    protected function addNamespace($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = basename($partition);
        if (!is_dir($this->dir.DIRECTORY_SEPARATOR.$partition)) {
            if (!@mkdir($this->dir.DIRECTORY_SEPARATOR.$partition)) {
                throw new CacheException('Could not add cache namespace directory');
            }
        }

        return $this->dir.DIRECTORY_SEPARATOR.$partition.DIRECTORY_SEPARATOR.$key;
    }
    /**
     * Clears a namespace.
     * @param  string|null $partition the namespace to clear (if not specified the default namespace is cleared)
     */
    public function clear($partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = basename($partition);
        if (is_dir($this->dir.DIRECTORY_SEPARATOR.$partition)) {
            foreach (scandir($this->dir.DIRECTORY_SEPARATOR.$partition) as $file) {
                if ($file == '.' || $file == '..') {
                    continue;
                }
                if (is_file($this->dir.DIRECTORY_SEPARATOR.$partition.DIRECTORY_SEPARATOR.$file)) {
                    unlink($this->dir.DIRECTORY_SEPARATOR.$partition.DIRECTORY_SEPARATOR.$file);
                }
            }
        }
    }
    /**
     * Prepare a key for insertion (reserve if you will).
     * Useful when a long running operation is about to happen and you do not want several clients to update the key at the same time.
     * @param  string  $key       the key to prepare
     * @param  string|null  $partition the namespace to store the key in (if not supplied the default will be used)
     */
    public function prepare($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        if (!@file_put_contents($key, 'wait')) {
            throw new CacheException('Could not prepare cache key');
        }
    }
    /**
     * Stora a value in a key.
     * @param  string  $key       the key to insert in
     * @param  mixed   $value     the value to be cached
     * @param  string|null  $partition the namespace to store the key in (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed the value that was stored
     */
    public function set($key, $value, $partition = null, $expires = 14400)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        if (is_string($expires)) {
            $expires = (int) strtotime($expires) - time();
        }
        if ((int) $expires <= 0) {
            $expires = 14400;
        }

        if (!(bool) @file_put_contents($key, serialize(array('created' => time(), 'expires' => time() + (int) $expires, 'data' => $value)))) {
            throw new CacheException('Could not set cache key');
        }

        return $value;
    }
    /**
     * Retrieve a value from cache.
     * @param  string  $key       the key to retrieve from
     * @param  mixed  $default   value to return if key is not found (defaults to `null`)
     * @param  string|null  $partition the namespace to look in (if not supplied the default is used)
     * @param  boolean $metaOnly  should only metadata be returned (defaults to false)
     * @return mixed             the stored value
     */
    public function get($key, $default = null, $partition = null, $metaOnly = false)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);

        $cntr = 0;
        while (true) {
            $value = @file_get_contents($key);
            if ($value === false) {
                return $default;
            }
            if ($value === 'wait') {
                if (++$cntr > 10) {
                    @unlink($key);
                    return $default;
                }
                usleep(500000);
                continue;
            }
            break;
        }

        $value = unserialize($value);
        if ($metaOnly) {
            unset($value['data']);
            return $value;
        }
        if ((int)$value['expires'] < time()) {
            @unlink($key);
            return $default;
        }

        return $value['data'];
    }
    /**
     * Remove a cached value.
     * @param  string $key       the key to remove
     * @param  string|null $partition the namespace to remove from (if not supplied the default namespace will be used)
     */
    public function delete($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        if (!@unlink($key)) {
            throw new CacheException('Could not delete cache key');
        }
    }
    /**
     * Get a cached value if it exists, if not - invoke a callback, store the result in cache and return it.
     * @param  string         $key       the key to look for / store in
     * @param  callable       $value     a function to invoke if the value is not present
     * @param  string|null         $partition the namespace to use (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed                     the cached value
     */
    public function getSet($key, callable $value, $partition = null, $time = 14400)
    {
        $temp = $this->get($key, null, $partition);
        if ($temp !== null) {
            return $temp;
        }
        $this->prepare($key, $partition);
        try {
            $value = call_user_func($value);
            return $this->set($key, $value, $partition, $time);
        } catch (CacheException $e) {
            return $value;
        } catch (\Exception $e) {
            $this->delete($key, $partition);
            throw $e;
        }
    }
}
