<?php

namespace vakata\cache;

class Dummy implements CacheInterface
{
    public function clear($partition = false)
    {
    }

    public function prepare($key, $partition = false)
    {
        throw new CacheException('Could not prepare cache key');
    }
    public function set($key, $value, $partition = false, $expires = 14400)
    {
        throw new CacheException('Could not set cache key');
    }
    public function get($key, $partition = false, $meta_only = false)
    {
        throw new CacheException('Could not get entry');
    }
    public function delete($key, $partition = false)
    {
        throw new CacheException('Could not delete cache key');
    }
    public function getSet($key, callable $value = null, $partition = false, $time = 14400)
    {
        try {
            return $this->get($key, $partition);
        } catch (CacheException $e) {
            try {
                $this->prepare($key, $partition);
            } catch (CacheException $ignore) {
            }
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
