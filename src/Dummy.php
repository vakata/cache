<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

class Dummy extends AbstractCache
{
    public function clear(): bool
    {
        return true;
    }
    public function set(string $key, mixed $value, null|string|int|DateInterval|DateTime $expires = 0): bool
    {
        return true;
    }
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }
    public function delete(string $key): bool
    {
        return true;
    }
    public function getSet(string $key, callable $value, null|string|int|DateInterval|DateTime $expires = 0): mixed
    {
        return call_user_func($value);
    }
}
