<?php

namespace vakata\cache;

class Memcache implements CacheInterface
{
    protected $connected = false;
    protected $memcache = null;
    protected $pool = array();
    protected $namespace = 'default';

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

    public function prepare($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        $this->memcache->set($key.'_meta', 'wait', MEMCACHE_COMPRESSED, time() + 10);
    }

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

    public function get($key, $partition = null, $metaOnly = false)
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
                throw new CacheException('Could not get cache meta');
            }
            if ($meta === 'wait') {
                if (++$cntr > 10) {
                    $this->memcache->delete($key.'_meta');
                    throw new CacheException('Could not get cache meta');
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
                throw new CacheException('Missing cache chunk');
            }
            $value .= $tmp;
        }
        $value = unserialize(base64_decode($value));

        return $value;
    }

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

    public function getSet($key, callable $value = null, $partition = null, $time = 14400)
    {
        try {
            return $this->get($key, $partition);
        } catch (CacheException $e) {
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
}
