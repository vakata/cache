<?php

namespace vakata\cache;

class Memcached implements CacheInterface
{
    protected $socket = null;
    protected $namespace = 'default';
    /**
     * Create an instance
     * @param  string      $address          the redis IP and port (127.0.0.1:6379)
     * @param  string      $defaultNamespace the default namespace to store in (namespaces are collections that can be easily cleared in bulk)
     */
    public function __construct($address = '127.0.0.1:11211', $defaultNamespace = 'default')
    {
        $address = parse_url('//' . ltrim($address, '/'));
        if (!$address) { $address = []; }
        $address = array_merge($address, [ 'host' => '127.0.0.1', 'port' => '11211']);
        $this->socket = fsockopen($address['host'], $address['port'], $num, $str, 3);
        if (!$this->socket) {
            throw new CacheException('Could not connect');
        }
    }

    public function __destruct()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }

    protected function _get($key)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        fwrite($this->socket, "get " . $key . "\r\n");
        $data = explode(" ", trim(fgets($this->socket), "\r\n"));
        if ($data[0] !== "VALUE" || $data[1] !== $key) {
            return null;
        }
        $length = $data[3];
        $return = "";
        while (strlen($return) < $length) {
            $return .= fread($this->socket, $length - strlen($return));
        }
        fgets($this->socket);
        fgets($this->socket);
        return $return;
    }
    protected function _set($key, $val, $exp = null)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        $length = strlen((string)$val);
        fwrite($this->socket, "set " . $key . " 0 " . ($exp !== null ? time() + $exp : 0) . " " . ((string)$length) . "\r\n");
        $written = 0;
        while ($written < $length) {
            $written += fwrite($this->socket, substr($val, $written));
        }
        fwrite($this->socket, "\r\n");
        $data = trim(fgets($this->socket), "\r\n");

        if ($data !== "STORED") {
            throw new CacheException("Could not store " . $data);
        }
        return $val;
    }
    protected function _del($key)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        fwrite($this->socket, "delete " . $key . "\r\n");
        fgets($this->socket);
    }
    protected function _incr($key)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        fwrite($this->socket, "incr " . $key . " 1\r\n");
        fgets($this->socket);
    }

    protected function addNamespace($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }

        $tmp = $this->_get($partition);
        if ((int)$tmp === 0) {
            $tmp = rand(1, 10000);
            $this->_set($partition, $tmp);
        }

        return $partition.'_'.$tmp.'_'.$key;
    }
    /**
     * Clears a namespace.
     * @param  string $partition the namespace to clear (if not specified the default namespace is cleared)
     */
    public function clear($partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $this->_incr($partition);
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
    }
    /**
     * Stora a value in a key.
     * @param  string  $key       the key to insert in
     * @param  mixed   $value     the value to be cached
     * @param  string  $partition the namespace to store the key in (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed the value that was stored
     */
    public function set($key, $value, $partition = null, $expires = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        if (is_string($expires)) {
            $expires = (int) strtotime($expires) - time();
        }
        if ($expires !== null && (int)$expires < 0) {
            $expires = 14400;
        }

        $orig_value = $value;
        $key = $this->addNamespace($key, $partition);
        $value = str_split(base64_encode(serialize($orig_value)), 1000 * 1000);

        $this->_set($key.'_meta', base64_encode(serialize(array('created' => time(), 'expires' => time() + $expires, 'chunks' => count($value)))), $expires);
        foreach ($value as $k => $v) {
            $this->_set($key.'_'.$k, $v, $expires);
        }
        return $orig_value;
    }
    /**
     * Retrieve a value from cache.
     * @param  string  $key       the key to retrieve from
     * @param  string  $default   value to return if key is not found (defaults to `null`)
     * @param  string  $partition the namespace to look in (if not supplied the default is used)
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
            $meta = $this->_get($key.'_meta');
            if ($meta === null) {
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

        $meta = unserialize(base64_decode($meta));
        if ($metaOnly) {
            return $meta;
        }
        $value = '';
        for ($i = 0; $i < $meta['chunks']; ++$i) {
            $tmp = $this->_get($key.'_'.$i);
            if ($tmp === null) {
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
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        $this->_del($key . '_meta');
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
