<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

class Dummy extends AbstractCache
{
    public function clear(): void
    {
    }
    public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool
    {
        return true;
    }
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }
    public function delete(string $key): void
    {
    }
    public function getSet(string $key, callable $value, string|int|DateInterval|DateTime $expires = 0): mixed
    {
        return call_user_func($value);
    }
}
