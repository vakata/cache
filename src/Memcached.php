<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

class Memcached extends AbstractCache
{
    protected mixed $socket;

    public function __construct(string $address = '127.0.0.1:11211', string $prefix = '')
    {
        parent::__construct($prefix);
        $address = parse_url('//' . ltrim($address, '/'));
        if (!$address) { $address = []; }
        $address = array_merge([ 'host' => '127.0.0.1', 'port' => '11211'], $address);
        $this->socket = fsockopen((string)$address['host'], (int)$address['port'], $num, $str, 3);
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

    protected function _get(string $key): mixed
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        fwrite($this->socket, "get " . $key . "\r\n");
        $data = explode(" ", trim(fgets($this->socket) ?: '', "\r\n"));
        if ($data[0] !== "VALUE" || $data[1] !== $key) {
            return null;
        }
        $length = (int)$data[3];
        $return = "";
        while (strlen($return) < $length) {
            $return .= fread($this->socket, max(0, $length - strlen($return)));
        }
        fgets($this->socket);
        fgets($this->socket);
        return $return;
    }
    protected function _set(string $key, string $val, int $exp = 0): bool
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        $length = strlen((string)$val);
        fwrite($this->socket, "set " . $key . " 0 " . $exp . " " . ((string)$length) . "\r\n");
        $written = 0;
        while ($written < $length) {
            $written += fwrite($this->socket, substr($val, $written));
        }
        fwrite($this->socket, "\r\n");
        $data = trim(fgets($this->socket) ?: '', "\r\n");

        if ($data !== "STORED") {
            return false;
        }
        return true;
    }
    protected function _del(string $key): void
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        fwrite($this->socket, "delete " . $key . "\r\n");
        fgets($this->socket);
    }

    public function clear(): void
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        fwrite($this->socket, "flush_all" . "\r\n");
        fgets($this->socket);
    }

    public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool
    {
        $key = $this->prefix . $key;
        // prefer the more robust seconds approach
        $expires = $expires === 0 ? 0 : $this->getExpiresSeconds($expires);
        // if the value exceeds tha max 30 days use a timestamp
        // but this may cause an issue with memcached's internal clock
        if ($expires > 60 * 60 * 24 * 30) {
            $expires = time() + $expires;
        }
        // split in less than 1mb chunks
        $value = str_split(serialize($value), 1 * 1000 * 1000);

        $res = true;
        foreach ($value as $k => $v) {
            $res = $res && $this->_set($key . ($k > 0 ? '__' . $k : ''), $v, $expires);
        }
        return $res;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;
        $val = '';
        $cnt = 0;
        do {
            $tmp = $this->_get($key . ($cnt > 0 ? '__' . $cnt : ''));
            if ($tmp === false) {
                break;
            }
            $val .= $tmp;
            if (strlen($tmp) < 1000 * 1000) {
                break;
            }
            $cnt ++;
        } while (true);
        $val = @unserialize($val);
        if ($val === false) {
            return $default;
        }
        return $val;
    }
    public function delete(string $key): void
    {
        $this->_del($this->prefix . $key);
    }
}
