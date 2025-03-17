<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

class Redis extends AbstractCache
{
    protected mixed $socket;

    public function __construct(string $address = '127.0.0.1:6379', string $prefix = '')
    {
        parent::__construct($prefix);
        $address = parse_url('//' . ltrim($address, '/'));
        if (!$address) { $address = []; }
        $address = array_merge([ 'host' => '127.0.0.1', 'port' => '6379'], $address);
        $this->socket = fsockopen((string)$address['host'], (int)$address['port'], $num, $str, 3);
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

    /**
     * @param string|array<scalar|null> $command
     * @return mixed
     */
    public function command(string|array $command): mixed
    {
        if (!is_array($command)) {
            $command = explode(" ", (string)$command);
        }
        foreach ($command as $k => $v) {
            $v = (string)$v;
            $command[$k] = '$' . strlen($v) . "\r\n" . $v . "\r\n";
        }
        $val = '*' . count($command) . "\r\n" . implode('', $command);
        $length = strlen($val);
        $written = 0;
        while ($written < $length) {
            $written += fwrite($this->socket, substr($val, $written));
        }
        return $this->_read();
    }
    protected function _read(): mixed
    {
        switch (fgetc($this->socket)) {
            case '+':
                return trim(fgets($this->socket) ?: '', "\r\n");
            case ':':
                return (int)trim(fgets($this->socket) ?: '', "\r\n");
            case '$':
                $length = (int)trim(fgets($this->socket) ?: '', "\r\n");
                if ($length === -1) {
                    return null;
                }
                $return = "";
                while (strlen($return) < $length) {
                    $return .= fread($this->socket, max(0, $length - strlen($return)));
                }
                fgets($this->socket);
                return $return;
            case '*':
                $length = (int)trim(fgets($this->socket) ?: '', "\r\n");
                $return = [];
                for ($i = 0; $i < $length; $i++) {
                    $return[] = $this->_read();
                }
                return $return;
            case '-':
                throw new CacheException(trim(fgets($this->socket) ?: '', "\r\n"));
        }
        throw new CacheException(trim(fgets($this->socket) ?: '', "\r\n"));
    }

    protected function _get(string $key): mixed
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        return $this->command(["GET", $key]);
    }
    protected function _set(string $key, mixed $val, int $exp = 0): mixed
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        if ($exp > time()) {
            $exp -= time();
        }
        return $exp === 0 ? $this->command(["SET", $key, $val]) : $this->command(["SET", $key, $val, 'EX', $exp]);
    }
    protected function _del(string $key): mixed
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        return $this->command(["DEL", $key]);
    }

    public function clear(): void
    {
        if (!$this->socket) {
            throw new CacheException('Cache not connected');
        }
        $this->command(["FLUSHALL"]);
    }

    public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool
    {
        $key = $this->prefix . $key;
        $value = serialize($value);
        $expires = $expires === 0 ? 0 : $this->getExpiresSeconds($expires);
        return $expires === 0 ?
            $this->_set($key, $value) :
            $this->_set($key, $value, $expires);
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;
        $value = $this->_get($key);
        if ($value === false) {
            return $default;
        }
        $value = @unserialize($value);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
    public function delete(string $key): void
    {
        $key = $this->prefix . $key;
        $this->_del($key);

    }
    public function pop(string $name, int $count = 1): mixed
    {
        return $this->command(['LPOP', $name, (string)$count]);
    }
    public function push(string $name, string $value): mixed
    {
        return $this->command(['RPUSH', $name, $value]);
    }
    public function len(string $name): int
    {
        return (int)$this->command(['LLEN', $name]);
    }
}
