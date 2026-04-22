<?php

namespace vakata\cache;

use \DateTime;
use \DateInterval;

class PHP extends AbstractCache
{
    protected string $file;
    protected int $touch;

    public function __construct(string $dir, string $prefix = '')
    {
        parent::__construct($prefix);
        $dir = realpath($dir) ?: throw new CacheException('Invalid cache dir');
        $this->file = $dir . '/' . sha1($prefix) . '.php';
        $this->touch = time() - 3600 * 24;
        if (is_file($this->file)) {
            touch($this->file, $this->touch);
        }
    }

    public function clear(): bool
    {
        if (is_file($this->file)) {
            return unlink($this->file);
        }
        return true;
    }

    public function set(string $key, mixed $value, null|string|int|DateInterval|DateTime $expires = 0): bool
    {
        $expires = $expires === 0 ? 0 : $this->getExpiresTimestamp($expires);
        $data = [];
        @include $this->file;
        $data['_' . md5($key)] = [
            'expires' => $expires,
            'value' => $value
        ];
        $temp = $this->file . '.' . uniqid('', true);
        file_put_contents(
            $temp,
            '<?php'."\n".'$data='.var_export($data, true).';',
            LOCK_EX
        );
        rename($temp, $this->file);
        touch($this->file, $this->touch);
        if (function_exists('opcache_compile_file')) {
            \opcache_invalidate($this->file, true);
            \opcache_compile_file($this->file);
        }
        return true;
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $data = [];
        @include $this->file;
        if (!isset($data['_' . md5($key)])) {
            return $default;
        }
        $value = $data['_' . md5($key)];
        if ($value['expires'] !== 0 && $value['expires'] < time()) {
            return $default;
        }
        return $value['value'];
    }
    public function delete(string $key): bool
    {
        $data = [];
        @include $this->file;
        unset($data['_' . md5($key)]);
        $temp = $this->file . '.' . uniqid('', true);
        file_put_contents(
            $temp,
            '<?php'."\n".'$data='.var_export($data, true).';',
            LOCK_EX
        );
        rename($temp, $this->file);
        if (function_exists('opcache_compile_file')) {
            @opcache_compile_file($this->file);
        }
        return true;
    }
}

