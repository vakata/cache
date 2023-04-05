<?php

namespace vakata\cache;

interface CacheInterface
{
    public function clear($partition = null);
    public function prepare($key, $partition = null);
    public function set($key, $value, $partition = null, $expires = 14400);
    public function get($key, $default = null, $partition = null, $metaOnly = false);
    public function delete($key, $partition = null);
    public function getSet($key, callable $value, $partition = null, $time = 14400);
    public function enableNamespaceCache();
    public function disableNamespaceCache();
}
