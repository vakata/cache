<?php

namespace vakata\cache;

class SHM extends CacheAbstract implements CacheInterface
{
    use CacheGetSetTrait;

    protected \SysvSemaphore $semaphore;
    protected \SysvSharedMemory $memory;
    protected array $data;
    protected bool $acquired = false;
    protected int $size;

    public function __construct(int $size = 3000000, int $id = 1)
    {
        $this->size = $size;
        $this->semaphore = sem_get($id);
        $this->memory = shm_attach($id, $size);
        $this->readMaster();
    }
    public function __destruct()
    {
        if ($this->memory) {
            shm_detach($this->memory);
        }
        if ($this->semaphore) {
            @sem_release($this->semaphore);
        }
    }

    protected function acquire(): bool
    {
        if ($this->acquired) {
            return false;
        }
        if (!sem_acquire($this->semaphore)) {
            throw new \Exception('Unable to acquire lock');
        }
        return $this->acquired = true;
    }
    protected function release()
    {
        if (!$this->acquired) {
            return;
        }
        if (!@sem_release($this->semaphore)) {
            throw new \Exception('Unable to release lock');
        }
        $this->acquired = false;
    }
    protected function getSize()
    {
        return $this->data['size'] + strlen(json_encode($this->data)) + 0.3 * $this->size;
    }

    public function reset()
    {
        $a = $this->acquire();
        for ($id = 0; $id < 9999; $id++) {
            if (shm_has_var($this->memory, $id)) {
                shm_remove_var($this->memory, $id);
            }
        }
        if ($a) {
            $this->release();
        }
        $this->readMaster();
    }

    protected function readMaster()
    {
        if ($this->acquire()) {
            $this->release();
        }
        $data = '';
        if (shm_has_var($this->memory, 1)) {
            $data = shm_get_var($this->memory, 1);
        }
        $this->data = json_decode($data, true) ?? [];
        if (!isset($this->data['keys'])) {
            $this->data['keys'] = [];
        }
        if (!isset($this->data['size'])) {
            $this->data['size'] = 0;
        }
        if (!isset($this->data['namespaces'])) {
            $this->data['namespaces'] = [];
        }
        if (!isset($this->data['max'])) {
            $this->data['max'] = 1;
        }
    }
    protected function writeMaster()
    {
        $a = $this->acquire();
        shm_put_var($this->memory, 1, json_encode($this->data));
        if ($a) {
            $this->release();
        }
    }

    protected function clean()
    {
        $a = $this->acquire();
        foreach ($this->data['keys'] as $key => $id) {
            if (!shm_has_var($this->memory, $id)) {
                unset($this->data['keys'][$key]);
            }
        }
        $items = [];
        foreach ($this->data['keys'] as $key => $id) {
            $temp = shm_get_var($this->memory, $id);
            $data = @unserialize($temp);
            if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
                shm_remove_var($this->memory, $id);
                unset($this->data['keys'][$key]);
                $this->data['size'] -= strlen($temp);
            } else {
                $items[$key] = $data['expires'];
            }
        }
        asort($items, SORT_NUMERIC);
        $items = array_keys(array_reverse($items));
        foreach ($items as $key) {
            if ($this->getSize() < $this->size / 2) {
                break;
            }
            $temp = shm_get_var($this->memory, $this->data['keys'][$key]);
            shm_remove_var($this->memory, $this->data['keys'][$key]);
            unset($this->data['keys'][$key]);
            $this->data['size'] -= strlen($temp);
        }
        $this->writeMaster();
        if ($a) {
            $this->release();
        }
    }

    protected function keyID(string $key, bool $create = false): int
    {
        if (!isset($this->data['keys'][$key])) {
            $this->readMaster();
        }
        if (!isset($this->data['keys'][$key])) {
            if (!$create) {
                return 0;
            }
            $a = $this->acquire();
            $this->data['keys'][$key] = ++$this->data['max'];
            $this->writeMaster();
            if ($a) {
                $this->release();
            }
        }
        return $this->data['keys'][$key];
    }

    protected function _get($key)
    {
        $key = $this->keyID($key);
        if (!$key) {
            return null;
        }
        if ($this->acquire()) {
            $this->release();
        }
        if (!shm_has_var($this->memory, $key)) {
            return null;
        }
        return shm_get_var($this->memory, $key);
    }
    protected function _set($key, $val, $exp = null)
    {
        if ($this->getSize() + strlen($val) > $this->size) {
            $this->clean();
        }
        $a = $this->acquire();
        $key = $this->keyID($key, true);
        $data = '';
        if (shm_has_var($this->memory, $key)) {
            $data = shm_get_var($this->memory, $key);
        }
        shm_put_var($this->memory, $key, $val);
        $this->data['size'] += strlen($val) - strlen($data);
        $this->writeMaster();
        if ($a) {
            $this->release();
        }
    }
    protected function _del($key)
    {
        $id = $this->keyID($key);
        if (!$id) {
            return;
        }
        $a = $this->acquire();
        $data = '';
        if (shm_has_var($this->memory, $id)) {
            $data = shm_get_var($this->memory, $id);
            shm_remove_var($this->memory, $id);
            $this->data['size'] -= strlen($data);
        }
        unset($this->data['keys'][$key]);
        $this->writeMaster();
        if ($a) {
            $this->release();
        }
    }

    protected function addNamespace($key, $partition = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }

        return $partition.'_'.$this->getNamespace($partition).'_'.$key;
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
        $a = $this->acquire();
        $this->data['namespaces'][$partition] += 1;
        $this->writeMaster();
        if ($a) {
            $this->release();
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
        $key = $this->addNamespace($key, $partition);
    }
    /**
     * Stora a value in a key.
     * @param  string  $key       the key to insert in
     * @param  mixed   $value     the value to be cached
     * @param  string|null  $partition the namespace to store the key in (if not supplied the default will be used)
     * @param  integer|string $expires   time in seconds (or strtotime parseable expression) to store the value for (14400 by default)
     * @return mixed the value that was stored
     */
    public function set($key, $value, $partition = null, $expires = null)
    {
        if (!$partition) {
            $partition = $this->namespace;
        }
        if (is_string($expires)) {
            $expires = (int) strtotime($expires);
        }
        if ($expires !== null && (int)$expires < 0) {
            $expires = 14400;
        }
        if ($expires < time() / 2) {
            $expires += time();
        }
        $key = $this->addNamespace($key, $partition);
        $this->_set($key, serialize(array('created' => time(), 'expires' => $expires, 'data' => $value)), $expires);
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
        $key = $this->addNamespace($key, $partition);

        $cntr = 0;
        while (true) {
            $value = $this->_get($key);
            if ($value === 'wait') {
                if (++$cntr > 10) {
                    return $default;
                }
                usleep(500000);
                continue;
            }
            break;
        }

        if ($value === null) {
            return $default;
        }

        $value = @unserialize($value);
        if ($value === false) {
            return $default;
        }
        if (isset($value['expires']) && $value['expires'] < time()) {
            return $default;
        }
        if ($metaOnly) {
            unset($value['data']);
            return $value;
        }
        return $value['data'];
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
        $key = $this->addNamespace($key, $partition);
        $this->_del($key);
    }

    protected function getNamespace($partition)
    {
        if (isset($this->namespaces) && isset($this->namespaces[$partition]) && $this->namespaces[$partition]) {
            return $this->namespaces[$partition];
        }
        if (!isset($this->data['namespaces'][$partition])) {
            $this->data['namespaces'][$partition] = rand(1,1000);
            $this->writeMaster();
        }
        $tmp = $this->data['namespaces'][$partition];
        if (is_array($this->namespaces)) {
            $this->namespaces[$partition] = $tmp;
        }
        return $tmp;
    }
}
