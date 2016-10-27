<?php

namespace vakata\cache;

class Memcache implements CacheInterface
{
    protected $connected = false;
    protected $memcache = null;
    protected $pool = array();
    protected $namespace = 'default';
    /**
     * Create an instance
     * @param  string      $pool             a memcached server or an array of servers
     * @param  string      $defaultNamespace the default namespace to store in (namespaces are collections that can be easily cleared in bulk)
     */
    public function __construct($pool = '127.0.0.1', $defaultNamespace = 'default')
    {
        if (is_string($pool)) {
            $pool = array('host' => $pool);
        }
        if (isset($pool['host'])) {
            $pool = array($pool);
        }
        $this->namespace = $defaultNamespace;
        $this->pool = $pool;
        $this->connect();
    }

    protected function connect()
    {
        $this->connected = false;
        $this->memcache = new \Memcache();
        foreach ($this->pool as $host) {
            $host = array_merge($host, array('port' => 11211, 'weight' => 1));
            $this->memcache->addServer($host['host'], $host['port'], true, $host['weight']);
            $stats = @$this->memcache->getExtendedStats();
            if ($this->connected || ($stats["{$host['host']}:{$host['port']}"] !== false && sizeof($stats["{$host['host']}:{$host['port']}"]) > 0)) {
                $this->connected = true;
            }
        }

        return $this->connected;
    }

    protected function addNamespace($key, $partition = null)
    {
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }

        $tmp = $this->memcache->get($partition);
        if ((int) $tmp === 0) {
            $tmp = rand(1, 10000);
            if (!$this->memcache->set($partition, $tmp, 0, 0)) {
                throw new CacheException('Could not add cache namespace');
            }
        }

        return $partition.'_'.$tmp.'_'.$key;
    }
    /**
     * Clears a namespace.
     * @param  string $partition the namespace to clear (if not specified the default namespace is cleared)
     */
    public function clear($partition = null)
    {
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }
        $this->memcache->increment($partition);
    }
    /**
     * Prepare a key for insertion (reserve if you will).
     * Useful when a long running operation is about to happen and you do not want several clients to update the key at the same time.
     * @param  string  $key       the key to prepare
     * @param  string  $partition the namespace to store the key in (if not supplied the default will be used)
     */
    public function prepare($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        $this->memcache->set($key.'_meta', 'wait', MEMCACHE_COMPRESSED, time() + 10);
    }
    /**
     * Stora a value in a key.
     * @param  string  $key       the key to insert in
     * @param  mixed   $value     the value to be cached
     * @param  string  $partition the namespace to store the key in (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed the value that was stored
     */
    public function set($key, $value, $partition = null, $expires = 14400)
    {
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }
        if (is_string($expires)) {
            $expires = (int) strtotime($expires) - time();
        }
        if ((int) $expires <= 0) {
            $expires = 14400;
        }

        $orig_value = $value;

        $key = $this->addNamespace($key, $partition);

        $value = str_split(base64_encode(serialize($value)), 1 * 1024 * 1024);

        $res = true;
        $res = $res && $this->memcache->set($key.'_meta', base64_encode(serialize(array('created' => time(), 'expires' => time() + $expires, 'chunks' => count($value)))), MEMCACHE_COMPRESSED, $expires);
        foreach ($value as $k => $v) {
            $res = $res && $this->memcache->set($key.'_'.$k, $v, MEMCACHE_COMPRESSED, $expires);
        }
        if (!$res) {
            throw new CacheException('Could not save cache key');
        }

        return $orig_value;
    }
    /**
     * Retrieve a value from cache.
     * @param  string  $key       the key to retrieve from
     * @param  string  $default   value to return if key is not found (defaults to `null`)
     * @param  string  $partition the namespace to look in (if not supplied the default is used)
     * @param  boolean $metaOnly  should only metadata be returned (defaults to `false`)
     * @return mixed             the stored value
     */
    public function get($key, $default = null, $partition = null, $metaOnly = false)
    {
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }

        $key = $this->addNamespace($key, $partition);

        $cntr = 0;
        while (true) {
            $meta = $this->memcache->get($key.'_meta');
            if ($meta === false) {
                return $default;
            }
            if ($meta === 'wait') {
                if (++$cntr > 10) {
                    $this->memcache->delete($key.'_meta');
                    return $default;
                }
                usleep(500000);
                continue;
            }
            break;
        }

        $meta = unserialize(base64_decode($meta));
        if ($metaOnly) {
            return $meta;
        }
        $value = '';
        for ($i = 0; $i < $meta['chunks']; ++$i) {
            $tmp = $this->memcache->get($key.'_'.$i);
            if ($tmp == false) {
                return $default;
            }
            $value .= $tmp;
        }
        $value = unserialize(base64_decode($value));

        return $value;
    }
    /**
     * Remove a cached value.
     * @param  string $key       the key to remove
     * @param  string $partition the namespace to remove from (if not supplied the default namespace will be used)
     */
    public function delete($key, $partition = null)
    {
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        if (!$this->memcache->delete($key.'_meta')) {
            throw new CacheException('Could not delete cache key');
        }
    }
    /**
     * Get a cached value if it exists, if not - invoke a callback, store the result in cache and return it.
     * @param  string         $key       the key to look for / store in
     * @param  callable       $value     a function to invoke if the value is not present
     * @param  string         $partition the namespace to use (if not supplied the default will be used)
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
