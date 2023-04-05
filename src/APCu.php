<?php

namespace vakata\cache;

class APCu extends CacheAbstract implements CacheInterface
{
    use CacheGetSetTrait;

    protected $namespace = 'default';

    protected function _get($key)
    {
        $res = false;
        $val = \apcu_fetch($key, $res);
        if (!$res) {
            return null;
        }
        return $val;
    }
    protected function _set($key, $val, $ttl = 0)
    {
        if ($ttl > time()) {
            $ttl -= time();
        }
        return \apcu_store($key, $val, $ttl);
    }
    protected function _inc($key)
    {
        return \apcu_inc($key);
    }
    protected function _del($key)
    {
        return \apcu_delete($key);
    }

    protected function addNamespace($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }

        return $partition.'_'.$this->getNamespace($partition).'_'.$key;
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
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
    }
    /**
     * Stora a value in a key.
     * @param  string  $key       the key to insert in
     * @param  mixed   $value     the value to be cached
     * @param  string|null  $partition the namespace to store the key in (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed the value that was stored
     */
    public function set($key, $value, $partition = null, $expires = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        if (is_string($expires)) {
            $expires = (int) strtotime($expires);
        }
        if ($expires !== null && (int)$expires < 0) {
            $expires = 14400;
        }
        if ($expires < time() / 2) {
            $expires += time();
        }
        $key = $this->addNamespace($key, $partition);
        $this->_set($key, serialize(array('created' => time(), 'expires' => $expires, 'data' => $value)), $expires);
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
            $value = $this->_get($key);
            if ($value === 'wait') {
                if (++$cntr > 10) {
                    return $default;
                }
                usleep(500000);
                continue;
            }
            break;
        }

        if ($value === null) {
            return $default;
        }

        $value = unserialize($value);
        if ($metaOnly) {
            unset($value['data']);
            return $value;
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
        $this->_del($key);
    }
}
