<?php

namespace vakata\cache;

use DateInterval;
use DateTime;
use SysvSemaphore;
use SysvSharedMemory;

class SHM extends AbstractCache
{
    protected SysvSemaphore $semaphore;
    protected SysvSharedMemory $memory;
    /**
     * @var array{keys:array<mixed>,size:int,max:int}
     */
    protected array $data;
    protected bool $acquired = false;
    protected int $size;

    public function __construct(int $size = 3000000, int $id = 1, string $prefix = '')
    {
        parent::__construct($prefix);
        $this->size = $size;
        $this->semaphore = sem_get($id) ?: throw new CacheException('Could not get semaphore');
        $this->memory = shm_attach($id, $size) ?: throw new CacheException('Could not attach memory');
        $this->readMaster();
    }
    public function __destruct()
    {
        @shm_detach($this->memory);
        @sem_release($this->semaphore);
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
    protected function release(): void
    {
        if (!$this->acquired) {
            return;
        }
        if (!@sem_release($this->semaphore)) {
            throw new \Exception('Unable to release lock');
        }
        $this->acquired = false;
    }
    protected function getSize(): int
    {
        return (int)ceil($this->data['size'] + strlen(json_encode($this->data) ?: '') + 0.3 * $this->size);
    }

    public function reset(): void
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

    protected function readMaster(): void
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
        if (!isset($this->data['max'])) {
            $this->data['max'] = 1;
        }
    }
    protected function writeMaster(): void
    {
        $a = $this->acquire();
        shm_put_var($this->memory, 1, json_encode($this->data));
        if ($a) {
            $this->release();
        }
    }

    protected function clean(): void
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

    protected function _get(string $key): mixed
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
    protected function _set(string $key, mixed $val, int $exp = 0): void
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
    protected function _del(string $key): void
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

    public function clear(): void
    {
        $this->reset();
        $this->writeMaster();
    }
    public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool
    {
        $key = $this->prefix . $key;
        $expires = $expires === 0 ? 0 : $this->getExpiresTimestamp($expires);
        $this->_set($key, serialize(array('expires' => $expires, 'data' => $value)), $expires);
        return $value;
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;
        $value = $this->_get($key);
        if ($value === null) {
            return $default;
        }
        $value = @unserialize($value);
        if ($value === false) {
            return $default;
        }
        if ($value['expires'] !== 0 && $value['expires'] < time()) {
            return $default;
        }
        return $value['data'];
    }
    public function delete(string $key): void
    {
        $key = $this->prefix . $key;
        $this->_del($key);
    }
}
