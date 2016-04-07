<?php

namespace vakata\cache;

class Redis implements CacheInterface
{
    protected $socket = null;
    protected $namespace = 'default';
    /**
     * Create an instance
     * @method __construct
     * @param  string      $address          the redis IP and port (127.0.0.1:6379)
     * @param  string      $defaultNamespace the default namespace to store in (namespaces are collections that can be easily cleared in bulk)
     */
    public function __construct($address = '127.0.0.1:6379', $defaultNamespace = 'default')
    {
        $address = parse_url('//' . ltrim($address, '/'));
        if (!$address) { $address = []; }
        $address = array_merge($address, [ 'host' => '127.0.0.1', 'port' => '6379']);
        $this->socket = fsockopen($address['host'], $address['port'], $num, $str, 3);
        if (!$this->socket) {
            throw new CacheException('Could not connect to Redis');
        }
    }

    public function __destruct()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }

    public function command($command)
    {
        if (!is_array($command)) {
            $command = explode(" ", $command);
        }
        foreach ($command as $k => $v) {
            $v = (string)$v;
            $command[$k] = '$' . strlen($v) . "\r\n" . $v . "\r\n";
        }
        fwrite($this->socket, '*' . count($command) . "\r\n" . implode('', $command));
        return $this->_read();
    }
    protected function _read()
    {
        switch (fgetc($this->socket)) {
            case '+':
                return trim(fgets($this->socket), "\r\n");
            case ':':
                return (int)trim(fgets($this->socket), "\r\n");
            case '$':
                $length = (int)trim(fgets($this->socket), "\r\n");
                if ($length === -1) {
                    return null;
                }
                $return = $length ? fread($this->socket, $length) : "";
                fgets($this->socket);
                return $return;
            case '*':
                $length = (int)trim(fgets($this->socket), "\r\n");
                $return = [];
                for ($i = 0; $i < $length; $i++) {
                    $return[] = $this->_read();
                }
                return $return;
            case '-':
                throw new CacheException(trim(fgets($this->socket), "\r\n"));
        }
    }

    protected function _get($key)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        return $this->command(["GET", $key]);
    }
    protected function _set($key, $val, $exp = null)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        return $exp === null ? $this->command(["SET", $key, $val]) : $this->command(["SET", $key, $val, 'EX', $exp]);
    }
    protected function _del($key)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        return $this->command(["DEL", $key]);
    }
    protected function _incr($key)
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        return $this->command(["INCR", $key]);
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
     * @method clear
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
     * @method prepare
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
     * @method set
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
        $key = $this->addNamespace($key, $partition);
        $this->_set($key, base64_encode(serialize(array('created' => time(), 'expires' => time() + $expires, 'data' => $value))), $expires);
        return $value;
    }
    /**
     * Retrieve a value from cache.
     * @method get
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

        $value = unserialize(base64_decode($value));
        if ($metaOnly) {
            unset($value['data']);
            return $value;
        }
        return $value['data'];
    }
    /**
     * Remove a cached value.
     * @method delete
     * @param  string $key       the key to remove
     * @param  string $partition the namespace to remove from (if not supplied the default namespace will be used)
     */
    public function delete($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        $this->_del($key);
    }
    /**
     * Get a cached value if it exists, if not - invoke a callback, store the result in cache and return it.
     * @method getSet
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
