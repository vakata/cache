<?php

namespace vakata\cache;

use DateInterval;
use DateTime;
use Redis;

class Libredis extends AbstractCache
{
    protected Redis $server;
    protected string $prefix = '';

    public function __construct(string $address = '127.0.0.1:6379', string $prefix = '')
    {
        $address = parse_url('//' . ltrim($address, '/'));
        if (!$address) { $address = []; }
        $address = array_merge([ 'host' => '127.0.0.1', 'port' => '6379'], $address);
        $this->server = new Redis();
        if (!$this->server->pconnect((string)$address['host'], (int)$address['port'])) {
            throw new CacheException('Could not connect to Redis');
        }
        $this->prefix = $prefix;
    }

    public function clear(): void
    {
        $this->server->flushAll();
    }
    public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool
    {
        $key = $this->prefix . $key;
        $value = serialize($value);
        $expires = $expires === 0 ? 0 : $this->getExpiresSeconds($expires);
        return $expires === 0 ?
            $this->server->set($key, $value) :
            $this->server->set($key, $value, [ 'ex' => $expires ]);
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;
        $value = $this->server->get($key);
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
        $this->server->del($key);
    }
}
