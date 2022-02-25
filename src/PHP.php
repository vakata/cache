<?php

namespace vakata\cache;

class PHP implements CacheInterface
{
    use CacheGetSetTrait;
    
    protected $dir;
    protected $namespace = 'default';

    /**
     * Create an instance
     * @param  string      $dir              the path to the directory where the cache files will be stored
     * @param  string      $defaultNamespace the default namespace to store in (namespaces are collections that can be easily cleared in bulk)
     */
    public function __construct($dir, $defaultNamespace = 'default')
    {
        $this->dir = @realpath($dir);
        if (!$this->dir) {
            throw new CacheException('Invalid cache dir');
        }
        $this->namespace = $defaultNamespace;
    }

    /**
     * Clears a namespace.
     * @param  string|null $partition the namespace to clear (if not specified the default namespace is cleared)
     */
    public function clear($partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = md5($partition) . '.php';
        if (is_file($this->dir . DIRECTORY_SEPARATOR . $partition)) {
            unlink($this->dir . DIRECTORY_SEPARATOR . $partition);
        }
    }
    /**
     * Prepare a key for insertion (reserve if you will).
     * Useful when a long running operation is about to happen and you do not want several clients to update the key at the same time.
     * @param  string  $key       the key to prepare
     * @param  string|null  $partition the namespace to store the key in (if not supplied the default will be used)
     */
    public function prepare($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = md5($partition) . '.php';
        $data = [];
        @include $this->dir . DIRECTORY_SEPARATOR . $partition;
        $data['_' . md5($key)] = '--\vakata\cache\PHP::wait--';
        $temp = $this->dir . DIRECTORY_SEPARATOR . $partition . '.' . uniqid('', true);
        file_put_contents(
            $temp,
            '<?php'."\n".'$data='.var_export($data, true).';',
            LOCK_EX
        );
        rename($temp, $this->dir . DIRECTORY_SEPARATOR . $partition);
        if (function_exists('opcache_compile_file')) {
            @opcache_compile_file($this->dir . DIRECTORY_SEPARATOR . $partition);
        }
    }
    /**
     * Stora a value in a key.
     * @param  string  $key       the key to insert in
     * @param  mixed   $value     the value to be cached
     * @param  string|null  $partition the namespace to store the key in (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed the value that was stored
     */
    public function set($key, $value, $partition = null, $expires = 14400)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = md5($partition) . '.php';
        if (is_string($expires)) {
            $expires = (int) strtotime($expires) - time();
        }
        if ((int) $expires <= 0) {
            $expires = 14400;
        }
        $data = [];
        @include $this->dir . DIRECTORY_SEPARATOR . $partition;
        $data['_' . md5($key)] = [
            'expires' => time() + $expires,
            'value' => $value
        ];
        $temp = $this->dir . DIRECTORY_SEPARATOR . $partition . '.' . uniqid('', true);
        file_put_contents(
            $temp,
            '<?php'."\n".'$data='.var_export($data, true).';',
            LOCK_EX
        );
        rename($temp, $this->dir . DIRECTORY_SEPARATOR . $partition);
        if (function_exists('opcache_compile_file')) {
            @opcache_compile_file($this->dir . DIRECTORY_SEPARATOR . $partition);
        }
        return $value;
    }
    /**
     * Retrieve a value from cache.
     * @param  string  $key       the key to retrieve from
     * @param  mixed  $default   value to return if key is not found (defaults to `null`)
     * @param  string|null  $partition the namespace to look in (if not supplied the default is used)
     * @param  boolean $metaOnly  should only metadata be returned (defaults to false)
     * @return mixed             the stored value
     */
    public function get($key, $default = null, $partition = null, $metaOnly = false)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = md5($partition) . '.php';
        $cntr = 0;
        while (true) {
            $data = [];
            @include $this->dir . DIRECTORY_SEPARATOR . $partition;
            if (!isset($data['_' . md5($key)])) {
                return $default;
            }
            if ($data['_' . md5($key)] !== '--\vakata\cache\PHP::wait--') {
                if (isset($data['_' . md5($key)]['expires']) && $data['_' . md5($key)]['expires'] >= time()) {
                    return $data['_' . md5($key)]['value'];
                }
                return $default;
            }
            if (++$cntr > 10) {
                unset($data['_' . md5($key)]);
                $temp = $this->dir . DIRECTORY_SEPARATOR . $partition . '.' . uniqid('', true);
                file_put_contents(
                    $temp,
                    '<?php'."\n".'$data='.var_export($data, true).';',
                    LOCK_EX
                );
                rename($temp, $this->dir . DIRECTORY_SEPARATOR . $partition);
                if (function_exists('opcache_compile_file')) {
                    @opcache_compile_file($this->dir . DIRECTORY_SEPARATOR . $partition);
                }
                return $default;
            }
            usleep(500000);
        }
    }
    /**
     * Remove a cached value.
     * @param  string $key       the key to remove
     * @param  string|null $partition the namespace to remove from (if not supplied the default namespace will be used)
     */
    public function delete($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = md5($partition) . '.php';
        $data = [];
        @include $this->dir . DIRECTORY_SEPARATOR . $partition;
        unset($data['_' . md5($key)]);
        $temp = $this->dir . DIRECTORY_SEPARATOR . $partition . '.' . uniqid('', true);
        file_put_contents(
            $temp,
            '<?php'."\n".'$data='.var_export($data, true).';',
            LOCK_EX
        );
        rename($temp, $this->dir . DIRECTORY_SEPARATOR . $partition);
        if (function_exists('opcache_compile_file')) {
            @opcache_compile_file($this->dir . DIRECTORY_SEPARATOR . $partition);
        }
    }
}
