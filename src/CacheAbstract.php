<?php

namespace vakata\cache;

abstract class CacheAbstract
{
    protected $namespace = 'default';
    protected $namespaces = null;

    abstract protected function _get($key);
    abstract protected function _del($key);
    abstract protected function _set($key, $val, $exp = 0);
    protected function _inc($key)
    {
        $tmp = $this->_get($key);
        if (!$tmp) {
            $tmp = 0;
        }
        $tmp ++;
        $this->_set($key, $tmp);
    }

    public function __construct($defaultNamespace = 'default', $cacheNamespaces = false)
    {
        $this->namespace = $defaultNamespace;
        $this->namespaces = $cacheNamespaces ? [] : null;
    }

    public function enableNamespaceCache()
    {
        if (!is_array($this->namespaces)) {
            $this->namespaces = [];
        }
    }
    public function disableNamespaceCache()
    {
        $this->namespaces = null;
    }

    protected function getNamespace($partition)
    {
        if (isset($this->namespaces) && isset($this->namespaces[$partition]) && $this->namespaces[$partition]) {
            return $this->namespaces[$partition];
        }
        $tmp = $this->_get($partition);
        if ((int)$tmp === 0) {
            $tmp = rand(1, 10000);
            $this->_set($partition, $tmp, 28 * 24 * 3600);
        }
        if (is_array($this->namespaces)) {
            $this->namespaces[$partition] = $tmp;
        }
        return $tmp;
    }
}