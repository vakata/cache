<?php

namespace vakata\cache;

class Libmemcached extends CacheAbstract implements CacheInterface
{
    use CacheGetSetTrait;
    
    protected $connected = false;
    protected $memcached = null;
    protected $pool = array();

    /**
     * Create an instance
     * @param  string      $pool             a memcached server or an array of servers
     * @param  string      $defaultNamespace the default namespace to store in (namespaces are collections that can be easily cleared in bulk)
     */
    public function __construct($pool = '127.0.0.1', $defaultNamespace = 'default')
    {
        parent::__construct($defaultNamespace);
        if (is_string($pool)) {
            $pool = [ $pool ];
        }
        foreach ($pool as $k => $v) {
            if (is_string($v)) {
                $v = parse_url('//' . ltrim($v, '/'));
                if (!$v) { $v = []; }
                $v = array_merge([ 'host' => '127.0.0.1', 'port' => 11211], $v);
                $pool[$k] = $v;
            }
            if (!isset($pool[$k]['weight'])) {
                $pool[$k]['weight'] = 1;
            }
        }
        $this->pool = $pool;
        $this->connect();
    }

    protected function _get($key)
    {
        return $this->memcached->get($key);
    }
    protected function _del($key)
    {
        return $this->memcached->delete($key);
    }
    protected function _set($key, $val, $exp = 0)
    {
        return $this->memcached->set($key, $val, $exp);
    }
    protected function _inc($key)
    {
        $this->memcached->increment($key, 1);
    }

    protected function connect()
    {
        $this->connected = false;
        $this->memcached = new \Memcached(sha1(json_encode($this->pool)));
        if (!count($this->memcached->getServerList())) {
            foreach ($this->pool as $host) {
                $this->memcached->addServer($host['host'], $host['port'], $host['weight']);
            }
            $this->memcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        }
        if (!$this->connected && count($this->memcached->getStats()?:[])) {
            $this->connected = true;
        }

        return $this->connected;
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
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }
        if (is_array($this->namespaces) && isset($this->namespaces[$partition])) {
            unset($this->namespaces[$partition]);
        }
        $this->_inc($partition, 1, 1);
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
        $this->_set($key.'_meta', 'wait', time() + 10);
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
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }
        if (is_string($expires)) {
            $expires = (int) strtotime($expires);
        }
        if ((int) $expires <= 0) {
            $expires = 14400;
        }
        if ($expires < time() / 2) {
            $expires += time();
        }

        $orig_value = $value;

        $key = $this->addNamespace($key, $partition);

        $value = str_split(serialize($value), 1 * 1000 * 1000);

        $res = true;
        $res = $res && $this->_set($key.'_meta', serialize(array('created' => time(), 'expires' => $expires, 'chunks' => count($value))), $expires);
        foreach ($value as $k => $v) {
            $res = $res && $this->_set($key.'_'.$k, $v, $expires);
        }
        if (!$res) {
            throw new CacheException('Could not save cache key');
        }

        return $orig_value;
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
        if (!$this->connected) {
            throw new CacheException('Cache not connected');
        }
        if (!$partition) {
            $partition = $this->namespace;
        }

        $key = $this->addNamespace($key, $partition);

        $cntr = 0;
        while (true) {
            $meta = $this->_get($key.'_meta');
            if ($meta === false) {
                return $default;
            }
            if ($meta === 'wait') {
                if (++$cntr > 10) {
                    $this->_del($key.'_meta');
                    return $default;
                }
                usleep(500000);
                continue;
            }
            break;
        }

        $temp = unserialize($meta);
        if ($temp === false) {
            return $default;
        }
        if ($metaOnly) {
            return $temp;
        }
        $value = '';
        for ($i = 0; $i < $temp['chunks']; ++$i) {
            $tmp = $this->_get($key.'_'.$i);
            if ($tmp == false) {
                return $default;
            }
            $value .= $tmp;
        }
        $value = unserialize($value);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
    /**
     * Remove a cached value.
     * @param  string $key       the key to remove
     * @param  string|null $partition the namespace to remove from (if not supplied the default namespace will be used)
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
        if (!$this->_del($key.'_meta')) {
            throw new CacheException('Could not delete cache key');
        }
        return true;
    }
}
