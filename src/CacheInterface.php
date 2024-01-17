<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

interface CacheInterface
{
    public function clear(): void;
    public function has(string $key): bool;
    public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool;
    public function get(string $key, mixed $default = null): mixed;
    public function delete(string $key): void;
    public function getSet(string $key, callable $value, string|int|DateInterval|DateTime $expires = 0): mixed;
    /**
     * @param iterable<string> $keys
     * @param mixed $default
     * @return array<string,mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array;
    /**
     * @param iterable<string,mixed> $values
     * @param integer $expires
     * @return array<string,bool>
     */
    public function setMultiple(iterable $values, string|int|DateInterval|DateTime $expires = 0): array;
    /**
     * @param iterable<string> $keys
     * @return void
     */
    public function deleteMultiple(iterable $keys): void;
}
