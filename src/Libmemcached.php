<?php

namespace vakata\cache;

use DateInterval;
use DateTime;
use Memcached;

class Libmemcached extends AbstractCache
{
    protected Memcached $memcached;
    protected bool $connected = false;
    /**
     * @var array<array{host:string,port:int,weight:int}>
     */
    protected array $pool = [];

    /**
     * @param string|array<string|array{host:string,port:int,weight:int}> $pool
     * @param string $prefix
     */
    public function __construct(array|string $pool = '127.0.0.1', string $prefix = '')
    {
        parent::__construct($prefix);

        if (is_string($pool)) {
            $pool = [ $pool ];
        }
        /**
         *  @var array<array{host:string,port:int,weight:int}>
         */
        $temp = [];
        foreach ($pool as $v) {
            /**
             * @var array{host:string,port:int,weight:int}
             */
            $server = [ 'host' => '127.0.0.1', 'port' => 11211, 'weight' => 1 ];
            if (is_string($v)) {
                $v = parse_url('//' . ltrim($v, '/'), PHP_URL_HOST | PHP_URL_PORT);
                if (!$v) { $v = []; }
            }
            if (is_array($v) && isset($v['host'])) {
                $server['host'] = $v['host'];
            }
            if (is_array($v) && isset($v['port'])) {
                $server['port'] = $v['port'];
            }
            if (is_array($v) && isset($v['weight'])) {
                $server['weight'] = $v['weight'];
            }
            $temp[] = $server;
        }
        $this->pool = $temp;
        $this->connect();
    }
    protected function connect(): bool
    {
        $this->connected = false;
        $this->memcached = new Memcached(sha1(json_encode($this->pool) ?: ''));
        if (!count($this->memcached->getServerList())) {
            foreach ($this->pool as $host) {
                $this->memcached->addServer($host['host'], $host['port'], $host['weight']);
            }
            $this->memcached->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        }
        if (count($this->memcached->getStats()?:[])) {
            $this->connected = true;
        }

        return $this->connected;
    }
    public function clear(): void
    {
        $this->memcached->flush();
    }
    public function set(string $key, mixed $value, string|int|DateInterval|DateTime $expires = 0): bool
    {
        $key = $this->prefix . $key;
        // prefer the more robust seconds approach
        $expires = $expires === 0 ? 0 : $this->getExpiresSeconds($expires);
        // if the value exceeds tha max 30 days use a timestamp
        // but this may cause an issue with memcached's internal clock
        if ($expires > 60 * 60 * 24 * 30) {
            $expires = time() + $expires;
        }
        // split in less than 1mb chunks
        $value = str_split(serialize($value), 1 * 1000 * 1000);

        $res = true;
        foreach ($value as $k => $v) {
            $res = $res && $this->memcached->set($key . ($k > 0 ? '__' . $k : ''), $v, $expires);
        }
        return $res;
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;
        $val = '';
        $cnt = 0;
        do {
            $tmp = $this->memcached->get($key . ($cnt > 0 ? '__' . $cnt : ''));
            if ($tmp === false) {
                break;
            }
            $val .= $tmp;
            if (strlen($tmp) < 1000 * 1000) {
                break;
            }
            $cnt ++;
        } while (true);
        $val = @unserialize($val);
        if ($val === false) {
            return $default;
        }
        return $val;
    }
    public function delete(string $key): void
    {
        $this->memcached->delete($this->prefix . $key);
    }
}
