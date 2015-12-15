# vakata\cache\Memcache


## Methods

| Name | Description |
|------|-------------|
|[__construct](#vakata\cache\memcache__construct)|Create an instance|
|[clear](#vakata\cache\memcacheclear)|Clears a namespace.|
|[prepare](#vakata\cache\memcacheprepare)|Prepare a key for insertion (reserve if you will).|
|[set](#vakata\cache\memcacheset)|Stora a value in a key.|
|[get](#vakata\cache\memcacheget)|Retrieve a value from cache.|
|[delete](#vakata\cache\memcachedelete)|Remove a cached value.|
|[getSet](#vakata\cache\memcachegetset)|Get a cached value if it exists, if not - invoke a callback, store the result in cache and return it.|

---



### vakata\cache\Memcache::__construct
Create an instance  


```php
public function __construct (  
    string $pool,  
    string $defaultNamespace  
)   
```

|  | Type | Description |
|-----|-----|-----|
| `$pool` | `string` | a memcached server or an array of servers |
| `$defaultNamespace` | `string` | the default namespace to store in (namespaces are collections that can be easily cleared in bulk) |

---


### vakata\cache\Memcache::clear
Clears a namespace.  


```php
public function clear (  
    string $partition  
)   
```

|  | Type | Description |
|-----|-----|-----|
| `$partition` | `string` | the namespace to clear (if not specified the default namespace is cleared) |

---


### vakata\cache\Memcache::prepare
Prepare a key for insertion (reserve if you will).  
Useful when a long running operation is about to happen and you do not want several clients to update the key at the same time.

```php
public function prepare (  
    string $key,  
    string $partition  
)   
```

|  | Type | Description |
|-----|-----|-----|
| `$key` | `string` | the key to prepare |
| `$partition` | `string` | the namespace to store the key in (if not supplied the default will be used) |

---


### vakata\cache\Memcache::set
Stora a value in a key.  


```php
public function set (  
    string $key,  
    mixed $value,  
    string $partition,  
    integer|string $expires  
) : mixed    
```

|  | Type | Description |
|-----|-----|-----|
| `$key` | `string` | the key to insert in |
| `$value` | `mixed` | the value to be cached |
| `$partition` | `string` | the namespace to store the key in (if not supplied the default will be used) |
| `$expires` | `integer`, `string` | time in seconds (or strtotime parseable expression) to store the value for (14400 by default) |
|  |  |  |
| `return` | `mixed` | the value that was stored |

---


### vakata\cache\Memcache::get
Retrieve a value from cache.  


```php
public function get (  
    string $key,  
    string $partition,  
    boolean $metaOnly  
) : mixed    
```

|  | Type | Description |
|-----|-----|-----|
| `$key` | `string` | the key to retrieve from |
| `$partition` | `string` | the namespace to look in (if not supplied the default is used) |
| `$metaOnly` | `boolean` | should only metadata be returned (defaults to false) |
|  |  |  |
| `return` | `mixed` | the stored value |

---


### vakata\cache\Memcache::delete
Remove a cached value.  


```php
public function delete (  
    string $key,  
    string $partition  
)   
```

|  | Type | Description |
|-----|-----|-----|
| `$key` | `string` | the key to remove |
| `$partition` | `string` | the namespace to remove from (if not supplied the default namespace will be used) |

---


### vakata\cache\Memcache::getSet
Get a cached value if it exists, if not - invoke a callback, store the result in cache and return it.  


```php
public function getSet (  
    string $key,  
    callable|null $value,  
    string $partition,  
    integer|string $expires  
) : mixed    
```

|  | Type | Description |
|-----|-----|-----|
| `$key` | `string` | the key to look for / store in |
| `$value` | `callable`, `null` | a function to invoke if the value is not present |
| `$partition` | `string` | the namespace to use (if not supplied the default will be used) |
| `$expires` | `integer`, `string` | time in seconds (or strtotime parseable expression) to store the value for (14400 by default) |
|  |  |  |
| `return` | `mixed` | the cached value |

---

