<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

class APCu extends AbstractCache
{
    public function clear(): bool
    {
        return \apcu_clear_cache();
    }
    public function set(string $key, mixed $value, null|string|int|DateInterval|DateTime $expires = 0): bool
    {
        return \apcu_store(
            $this->prefix . $key,
            serialize($value),
            $expires === 0 ? 0 : $this->getExpiresSeconds($expires)
        );
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $res = false;
        $val = \apcu_fetch($this->prefix . $key, $res);
        if (!$res) {
            return $default;
        }
        $val = @unserialize($val);
        if ($val === false) {
            return $default;
        }
        return $val;
    }
    public function delete(string $key): bool
    {
        return \apcu_delete($this->prefix . $key);
    }
}
