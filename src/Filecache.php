<?php

namespace vakata\cache;

use DateInterval;
use DateTime;

class Filecache extends AbstractCache
{
    protected string $dir;
    protected string $prefix = '';

    public function __construct(string $dir, string $prefix = '')
    {
        parent::__construct($prefix);
        $this->dir = realpath($dir) ?: throw new CacheException('Invalid cache dir');
    }
    public function clear(): bool
    {
        $ignore = [
            '.gitignore',
            '.gitkeep',
            '.htaccess'
        ];
        $ret = true;
        foreach (scandir($this->dir) ?: [] as $file) {
            if (is_file($this->dir . DIRECTORY_SEPARATOR . $file) && !in_array($file, $ignore)) {
                $ret = $ret && unlink($this->dir . DIRECTORY_SEPARATOR . $file);
            }
        }
        return $ret;
    }
    public function set(string $key, mixed $value, null|string|int|DateInterval|DateTime $expires = 0): bool
    {
        $key = $this->dir . DIRECTORY_SEPARATOR . $this->prefix . $key;
        $expires = $expires === 0 ? 0 : $this->getExpiresTimestamp($expires);
        return !!@file_put_contents($key, serialize([ 'expires' => $expires, 'data' => $value ]));
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->dir . DIRECTORY_SEPARATOR . $this->prefix . $key;
        $value = @file_get_contents($key);
        if ($value === false) {
            return $default;
        }
        $value = @unserialize($value);
        if ($value === false) {
            return $default;
        }
        if ($value['expires'] !== 0 && $value['expires'] < time()) {
            @unlink($key);
            return $default;
        }
        return $value['data'];
    }
    public function delete(string $key): bool
    {
        $key = $this->dir . DIRECTORY_SEPARATOR . $this->prefix . $key;
        return @unlink($key);
    }
}
