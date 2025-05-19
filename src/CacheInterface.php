<?php

namespace vakata\cache;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface as CI;

interface CacheInterface extends CI
{
    public function clear(): bool;
    public function has(string $key): bool;
    public function set(string $key, mixed $value, null|string|int|DateInterval|DateTime $expires = 0): bool;
    public function get(string $key, mixed $default = null): mixed;
    public function delete(string $key): bool;
    public function getSet(string $key, callable $value, null|string|int|DateInterval|DateTime $expires = 0): mixed;
    /**
     * @param iterable<string> $keys
     * @param mixed $default
     * @return array<string,mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array;
    /**
     * @param iterable<string,mixed> $values
     * @param integer $expires
     * @return bool
     */
    public function setMultiple(iterable $values, null|string|int|DateInterval|DateTime $expires = 0): bool;
    /**
     * @param iterable<string> $keys
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool;
}
