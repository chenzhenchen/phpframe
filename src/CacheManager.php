<?php

namespace PHPFrame;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Closure;

/**
 * 缓存管理器
 * Cache Manager
 *
 * 提供统一的缓存操作接口，支持多种缓存驱动
 * Provides unified cache operation interface, supports multiple cache drivers
 * 包含缓存统计、批量操作、模式删除等高级功能
 * Includes advanced features like cache statistics, batch operations, pattern deletion
 */
class CacheManager
{
    /**
     * @var CacheRepository 缓存实例 Cache instance
     */
    protected $cache;

    protected $prefix = '';

    protected $ttl = 86400;

    /**
     * 构造函数
     * Constructor
     *
     * @param CacheRepository $cache 缓存实例 Cache instance
     */
    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
        $this->prefix = config('cache.prefix', '');
        $this->ttl = config('cache.ttl', $this->ttl);
    }

    /**
     * 获取真实的缓存key
     * Get real cache key
     *
     * @param string $key
     * @return string
     */
    private function getKey(string $key) {
        // 支持多服务共享key，以@开头的key不添加前缀
        if (str_starts_with($key, '@')) {
            $key = substr($key, 1);
        } else {
            $key = "{$this->prefix}:" . $key;
        }
        return $key;
    }
    /**
     * 获取缓存值
     * Get cache value
     *
     * @param string $key 缓存键 Cache key
     * @param mixed $default 默认值 Default value
     * @return mixed
     */
    public function get(string $key, $default = null): mixed
    {
        $value = $this->cache->get($this->getKey($key), $default);
        return $value;
    }

    /**
     * 设置缓存值
     * Set cache value
     *
     * @param string $key 缓存键 Cache key
     * @param mixed $value 缓存值 Cache value
     * @param mixed $ttl 过期时间 Time to live
     * @return bool
     */
    public function set(string $key, $value, $ttl = null): bool
    {
        $result = $this->cache->set($this->getKey($key), $value, $ttl ?? $this->ttl);
        return $result;
    }

    /**
     * 删除缓存
     * Delete cache
     *
     * @param string $key 缓存键 Cache key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $result = $this->cache->delete($this->getKey($key));
        return $result;
    }

    /**
     * 检查缓存是否存在
     * Check if cache exists
     *
     * @param string $key 缓存键 Cache key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->cache->has($this->getKey($key));
    }

    /**
     * 记住缓存值（如果不存在则设置）
     * Remember cache value (set if not exists)
     *
     * @param string $key 缓存键 Cache key
     * @param mixed $ttl 过期时间 Time to live
     * @param Closure $callback 回调函数 Callback function
     * @return mixed
     */
    public function remember(string $key, $ttl, Closure $callback)
    {
        return $this->cache->remember($this->getKey($key), $ttl, $callback);
    }

    /**
     * 递增缓存值
     * Increment cache value
     *
     * @param string $key 缓存键 Cache key
     * @param int $value 递增步长 Increment step
     * @return int
     */
    public function increment(string $key, int $value = 1)
    {
        return $this->cache->increment($this->getKey($key), $value);
    }

    /**
     * 递减缓存值
     * Decrement cache value
     *
     * @param string $key 缓存键 Cache key
     * @param int $value 递减步长 Decrement step
     * @return int
     */
    public function decrement(string $key, int $value = 1)
    {
        return $this->cache->decrement($this->getKey($key), $value);
    }

    /**
     * 清除所有缓存
     * Clear all cache
     *
     * @return bool
     */
    public function clear(): bool
    {
        return $this->cache->clear();
    }


    /**
     * 按模式删除缓存（仅支持Redis驱动）
     * Delete cache by pattern (Redis driver only)
     *
     * @param string $pattern 模式匹配 Pattern match
     * @return int
     */
    public function deleteByPattern(string $pattern): int
    {
        $deleted = 0;

        // 只有Redis驱动支持模式删除
        if (config('cache.default') === 'redis') {
            try {
                // 获取Redis连接实例
                $redis = app('redis.connection');

                // 构建完整的键模式
                $prefix = config('cache.prefix', '');
                $fullPattern = $prefix ? "{$prefix}:{$pattern}" : $pattern;

                // 使用scan命令避免阻塞（生产环境推荐）
                $iterator = null;
                $keys = [];

                do {
                    $result = $redis->scan($iterator, $fullPattern, 100);
                    if ($result !== false) {
                        $keys = array_merge($keys, $result);
                    }
                } while ($iterator > 0);

                // 删除匹配的键
                if (!empty($keys)) {
                    $deleted = $this->deleteMultiple($keys);
                }

            } catch (\Exception $e) {
                // 记录错误但不中断程序
                logger()->warning("模式删除缓存失败: " . $e->getMessage());
            }
        } else {
            logger()->warning("模式删除缓存仅支持Redis驱动，当前驱动: " . config('cache.default'));
        }

        return $deleted;
    }

    /**
     * 获取缓存实例
     * Get cache instance
     *
     * @return CacheRepository
     */
    public function getCacheInstance(): CacheRepository
    {
        return $this->cache;
    }
    
    /**
     * 拉取缓存值（获取后删除）
     * Pull cache value (get and delete)
     *
     * @param string $key 缓存键 Cache key
     * @param mixed $default 默认值 Default value
     * @return mixed
     */
    public function pull(string $key, $default = null): mixed   {
        $key = $this->getKey($key);
        $value = $this->get($key, $default);
        if ($value !== $default) {
            $this->delete($key);
        }
        return $value;
    }

    /**
     * 删除缓存（别名）
     * Delete cache (alias)
     *
     * @param string $key 缓存键 Cache key
     * @return bool
     */
    public function forget(string $key): bool
    {
        return $this->delete($this->getKey($key));
    }

    /**
     * 清除所有缓存（别名）
     * Clear all cache (alias)
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * 删除多个缓存
     * Delete multiple cache entries
     *
     * @param iterable $keys 缓存键数组 Cache keys array
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $result = true;
        foreach ($keys as $key) {
            if (!$this->delete($this->getKey($key))) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * 永久记住缓存值
     * Remember cache value forever
     *
     * @param string $key 缓存键 Cache key
     * @param Closure $callback 回调函数 Callback function
     * @return mixed
     */
    public function rememberForever(string $key, Closure $callback)
    {
        return $this->cache->rememberForever($this->getKey($key), $callback);
    }

    /**
     * 永久设置缓存值
     *
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @return bool
     */
    public function forever(string $key, $value): bool
    {
        return $this->cache->forever($this->getKey($key), $value);
    }

    /**
     * 获取缓存存储实例
     *
     * @return Store
     */
    public function getStore(): Store
    {
        return $this->cache->getStore();
    }

    /**
     * 设置缓存值（别名）
     *
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param mixed $ttl 过期时间
     * @return bool
     */
    public function put(string $key, $value, $ttl = null): bool
    {
        return $this->set($this->getKey($key), $value, $ttl ?? 3600);
    }
}