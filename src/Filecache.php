<?php

namespace vakata\cache;

class Filecache implements CacheInterface
{
    protected $dir = false;
    protected $namespace = 'default';

    public function __construct($dir, $defaultNamespace = 'default')
    {
        $this->dir = @realpath($dir);
        if (!$this->dir) {
            throw new CacheException('Invalid cache dir');
        }
        $this->namespace = $defaultNamespace;
    }

    protected function addNamespace($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = basename($partition);
        if (!is_dir($this->dir.DIRECTORY_SEPARATOR.$partition)) {
            if (!@mkdir($this->dir.DIRECTORY_SEPARATOR.$partition)) {
                throw new CacheException('Could not add cache namespace directory');
            }
        }

        return $this->dir.DIRECTORY_SEPARATOR.$partition.DIRECTORY_SEPARATOR.$key;
    }

    public function clear($partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $partition = basename($partition);
        if (is_dir($this->dir.DIRECTORY_SEPARATOR.$partition)) {
            foreach (scandir($this->dir.DIRECTORY_SEPARATOR.$partition) as $file) {
                if ($file == '.' || $file == '..') {
                    continue;
                }
                if (is_file($this->dir.DIRECTORY_SEPARATOR.$partition.DIRECTORY_SEPARATOR.$file)) {
                    unlink($this->dir.DIRECTORY_SEPARATOR.$partition.DIRECTORY_SEPARATOR.$file);
                }
            }
        }
    }

    public function prepare($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        if (!@file_put_contents($key, 'wait')) {
            throw new CacheException('Could not prepare cache key');
        }
    }

    public function set($key, $value, $partition = null, $expires = 14400)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        if (is_string($expires)) {
            $expires = (int) strtotime($expires) - time();
        }
        if ((int) $expires <= 0) {
            $expires = 14400;
        }

        if (!(bool) @file_put_contents($key, base64_encode(serialize(array('created' => time(), 'expires' => time() + (int) $expires, 'data' => $value))))) {
            throw new CacheException('Could not set cache key');
        }

        return $value;
    }

    public function get($key, $partition = null, $metaOnly = false)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);

        $cntr = 0;
        while (true) {
            $value = @file_get_contents($key);
            if ($value === false) {
                throw new CacheException('Could not get entry');
            }
            if ($value === 'wait') {
                if (++$cntr > 10) {
                    @unlink($key);
                    throw new CacheException('Could not get cache meta');
                }
                usleep(500000);
                continue;
            }
            break;
        }

        $value = unserialize(base64_decode($value));
        if ($metaOnly) {
            unset($value['data']);

            return $value;
        }
        if ((int) $value['expires'] < time()) {
            @unlink($key);
            throw new CacheException('Cache content is expired');
        }

        return $value['data'];
    }

    public function delete($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        $key = $this->addNamespace($key, $partition);
        if (!@unlink($key)) {
            throw new CacheException('Could not delete cache key');
        }
    }

    public function getSet($key, callable $value = null, $partition = null, $time = 14400)
    {
        try {
            return $this->get($key, $partition);
        } catch (CacheException $e) {
            $this->prepare($key, $partition);
            try {
                $value = call_user_func($value);

                return $this->set($key, $value, $partition, $time);
            } catch (CacheException $e) {
                return $value;
            } catch (\Exception $e) {
                $this->delete($key, $partition);
                throw $e;
            }
        }
    }
}
