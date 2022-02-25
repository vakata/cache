<?php

namespace vakata\cache;

class APC implements CacheInterface
{
    protected $namespace = 'default';
    /**
     * Create an instance
     * @param  string      $defaultNamespace the default namespace to store in (namespaces are collections that can be easily cleared in bulk)
     */
    public function __construct($defaultNamespace = 'default')
    {
        $this->namespace = $defaultNamespace;
    }

    protected function _get($key)
    {
        $res = false;
        $val = apcu_fetch($key, $res);
        if (!$res) {
            throw new CacheException('Missing value');
        }
        return $val;
    }
    protected function _set($key, $val, $ttl = 0)
    {
        return apcu_store($key, $val, $ttl);
    }
    protected function _inc($key)
    {
        return apcu_inc($key);
    }
    protected function _del($key)
    {
        return apcu_delete($key);
    }

    protected function addNamespace($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        try {
            $tmp = $this->_get($partition);
        } catch (CacheException $e) {
            $tmp = 0;
        }
        if ((int)$tmp === 0) {
            $tmp = rand(1, 10000);
            if (!$this->_set($partition, $tmp)) {
                throw new CacheException('Could not add cache namespace');
            }
        }
        return $partition.'_'.$tmp.'_'.$key;
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
        $this->_inc($partition);
    }
    /**
     * Prepare a key for insertion (reserve if you will).
     * Useful when a long running operation is about to happen and you do not want several clients to update the key at the same time.
     * @param  string  $key       the key to prepare
     * @param  string|null  $partition the namespace to store the key in (if not supplied the default will be used)
     */
    public function prepare($key, $partition = null)
    {
        $key = $this->addNamespace($key, $partition);
        $this->_set($key, '--\vakata\cache\APC::wait--', 10);
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
        if (is_string($expires)) {
            $expires = (int) strtotime($expires) - time();
        }
        if ((int) $expires <= 0) {
            $expires = 14400;
        }
        $key = $this->addNamespace($key, $partition);
        $this->_set($key, $value, $expires);
        return $value;
    }
    /**
     * Retrieve a value from cache.
     * @param  string  $key       the key to retrieve from
     * @param  mixed  $default   value to return if key is not found (defaults to `null`)
     * @param  string|null  $partition the namespace to look in (if not supplied the default is used)
     * @param  boolean $metaOnly  should only metadata be returned (defaults to `false`)
     * @return mixed             the stored value
     */
    public function get($key, $default = null, $partition = null, $metaOnly = false)
    {
        $key = $this->addNamespace($key, $partition);
        $cntr = 0;
        while (true) {
            try {
                $value = $this->_get($key);
            } catch (CacheException $e) {
                return $default;
            }
            if ($value !== '--\vakata\cache\APC::wait--') {
                return $value;
            }
            if (++$cntr > 10) {
                $this->_del($key);
                return $default;
            }
            usleep(500000);
        }
    }
    /**
     * Remove a cached value.
     * @param  string $key       the key to remove
     * @param  string|null $partition the namespace to remove from (if not supplied the default namespace will be used)
     */
    public function delete($key, $partition = null)
    {
        $key = $this->addNamespace($key, $partition);
        $this->_del($key);
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
        $temp = $this->get($key, chr(0), $partition);
        if ($temp !== chr(0)) {
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
