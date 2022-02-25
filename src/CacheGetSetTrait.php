<?php

namespace vakata\cache;

trait CacheGetSetTrait
{
    /**
     * Get a cached value if it exists, if not - invoke a callback, store the result in cache and return it.
     * @param  string         $key       the key to look for / store in
     * @param  callable       $value     a function to invoke if the value is not present
     * @param  string|null         $partition the namespace to use (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed                     the cached value
     */
    public function getSet($key, callable $value, $partition = null, $time = 14400)
    {
        $temp = $this->get($key, chr(0), $partition);
        if ($temp !== chr(0)) {
            return $temp;
        }
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
