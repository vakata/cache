<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

abstract class AbstractCache implements CacheInterface
{
    protected string $prefix = '';

    public function __construct(string $prefix = '')
    {
        $this->prefix = $prefix;
    }

    abstract public function get(string $key, mixed $default = null): mixed;
    abstract public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool;
    abstract public function delete(string $key): void;
    abstract public function clear(): void;

    protected function getExpiresTimestamp(int|string|DateInterval|DateTime $expires): int
    {
        if ($expires instanceof DateInterval) {
            $expires = (new \DateTime())->add($expires)->getTimestamp();
        }
        if ($expires instanceof DateTime) {
            $expires = $expires->getTimestamp();
        }
        if (is_string($expires)) {
            $expires = (int)strtotime($expires);
        }
        if ($expires < 0) {
            $expires = 0;
        }
        if ($expires && $expires < time() / 2) {
            $expires += time();
        }
        return $expires;
    }
    protected function getExpiresSeconds(int|string|DateInterval|DateTime $expires): int
    {
        return max(0, $this->getExpiresTimestamp($expires) - time());
    }

    public function getSet(string $key, callable $value, string|int|DateInterval|DateTime $expires = 0): mixed
    {
        $cnt = 0;
        do {
            $temp = $this->get($key, chr(0));
            // not default and not wait - return the value
            if ($temp !== chr(0) && $temp !== chr(1)) {
                return $temp;
            }
            // the default - the key does not exist - break and set
            if ($temp === chr(0)) {
                break;
            }
            // value is being generated - wait for value
            if ($temp === chr(1)) {
                usleep(200000);
            }
            $cnt ++;
        } while ($cnt < 50);

        $this->set($key, chr(1), 10);
        try {
            $value = call_user_func($value);
            $this->set($key, $value, $expires);
            return $value;
        } catch (CacheException $e) {
            return $value;
        } catch (\Throwable $e) {
            $this->delete($key);
            throw $e;
        }
    }
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $temp = [];
        foreach ($keys as $key) {
            $temp[$key] = $this->get($key, $default);
        }
        return $temp;
    }
    public function setMultiple(iterable $values, string|int|DateInterval|DateTime $expires = 0): array
    {
        $temp = [];
        foreach ($values as $key => $value) {
            $temp[$key] = $this->set($key, $value, $expires);
        }
        return $temp;
    }
    public function deleteMultiple(iterable $keys): void
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
    }
    public function has(string $key): bool
    {
        return $this->has($key);
    }
}
