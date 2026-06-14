# 缓存

PHPFrame 基于 `illuminate/cache` 提供缓存能力，通过 `CacheManager` 封装了统一的缓存操作接口。

## 配置

参考 `config/cache.php`：

```php
return [
    'default' => env('CACHE_DRIVER', 'redis'),
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => runtime_path('cache'),
        ],
        'redis' => [
            'driver' => 'redis',
            'client' => env('REDIS_CLIENT', 'predis'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', ''),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'phpframe'),
];
```

## 基本使用

### 门面方式

```php
use PHPFrame\Facades\Cache;

// 获取
$value = Cache::get('key');
$value = Cache::get('key', 'default');

// 设置（默认 TTL 由 cache.ttl 配置决定）
Cache::set('key', 'value');
Cache::set('key', 'value', 3600); // 1 小时

// 删除
Cache::delete('key');
Cache::forget('key'); // delete 的别名

// 检查存在
if (Cache::has('key')) {
    // ...
}

// 获取后删除
$value = Cache::pull('key');
```

### 容器方式

```php
$cache = app('cache');
$cache->get('key');
$cache->set('key', 'value', 3600);
```

## 高级用法

### 记住缓存

```php
// 如果缓存不存在，执行回调并缓存结果
$value = Cache::remember('users.active', 3600, function () {
    return Db::table('users')->where('active', 1)->get();
});

// 永久记住
$value = Cache::rememberForever('config.app', function () {
    return Config::all();
});
```

### 递增/递减

```php
Cache::set('counter', 0);
Cache::increment('counter');      // 1
Cache::increment('counter', 5);   // 6
Cache::decrement('counter');      // 5
Cache::decrement('counter', 3);   // 2
```

### 模式删除（仅 Redis）

```php
// 删除所有匹配模式的缓存键
$deleted = Cache::deleteByPattern('db_query:*');
$deleted = Cache::deleteByPattern('user:*');
```

> `deleteByPattern()` 使用 Store 的前缀构建完整键模式，确保与 `get()`/`set()` 等方法的键格式一致。匹配到的键会通过 `delete()` 方法逐个删除，保证前缀处理的一致性。内部通过 `app('redis')` 获取 Redis 连接实例，与容器注册的服务名一致。

### 批量删除

```php
Cache::deleteMultiple(['key1', 'key2', 'key3']);
```

### 清除所有缓存

```php
Cache::clear();
Cache::flush(); // clear 的别名
```

### 永久缓存

```php
Cache::forever('config', $configData);
```

## 缓存前缀

所有缓存键会自动添加前缀（由 `cache.prefix` 配置）：

```php
Cache::set('users', $data);
// 实际存储的键: phpframe:users
```

以 `@` 开头的键不添加前缀（用于多服务共享）：

```php
Cache::set('@shared_config', $data);
// 实际存储的键: shared_config（无前缀）
```

> 注意：`pull()`、`forget()`、`put()` 等方法内部已正确处理前缀，不会出现双重前缀问题。

## CacheManager API

| 方法 | 说明 |
|------|------|
| `get($key, $default)` | 获取缓存 |
| `set($key, $value, $ttl)` | 设置缓存 |
| `delete($key)` | 删除缓存 |
| `has($key)` | 检查存在 |
| `remember($key, $ttl, $callback)` | 记住缓存 |
| `rememberForever($key, $callback)` | 永久记住 |
| `forever($key, $value)` | 永久设置 |
| `pull($key, $default)` | 获取后删除 |
| `forget($key)` | 删除（别名） |
| `increment($key, $value)` | 递增 |
| `decrement($key, $value)` | 递减 |
| `deleteByPattern($pattern)` | 模式删除（仅 Redis） |
| `deleteMultiple($keys)` | 批量删除 |
| `clear()` / `flush()` | 清除所有 |
| `put($key, $value, $ttl)` | 设置（别名） |
| `getCacheInstance()` | 获取底层 CacheRepository |
| `getStore()` | 获取底层 Store |
