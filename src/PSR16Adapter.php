<?php

namespace vakata\cache;

use Psr\SimpleCache\CacheInterface as CI;

class PSR16Adapter implements CI
{
    protected CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function get($key, $default = null)
    {
        return $this->cache->get($key, $default);
    }
    public function set($key, $value, $ttl = null)
    {
        return $this->cache->set($key, $value, $ttl ?? 0);
    }
    /**
     * @param iterable<string> $keys
     * @param mixed $default
     * @return iterable<string,mixed>
     */
    public function getMultiple($keys, $default = null)
    {
        return $this->cache->getMultiple($keys, $default);
    }
    /**
     * @param iterable<string,mixed> $values
     * @param null|string|int|\DateInterval $ttl
     * @return bool
     */
    public function setMultiple($values, $ttl = null)
    {
        $this->cache->setMultiple($values, $ttl ?? 0);
        return true;
    }
    public function has($key)
    {
        return $this->cache->has($key);
    }
    public function delete($key)
    {
        $this->cache->delete($key);
        return true;
    }
    public function clear()
    {
        $this->cache->clear();
        return true;
    }
    /**
     * @param iterable<string> $keys
     * @return bool
     */
    public function deleteMultiple($keys)
    {
        $this->cache->deleteMultiple($keys);
        return true;
    }
}
