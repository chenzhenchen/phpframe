<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 缓存门面类
 * Cache Facade Class
 * 使用方式：Cache::get('key'), Cache::set('key', 'value')
 * Usage: Cache::get('key'), Cache::set('key', 'value')
 * 
 * @method static mixed get(string $key, mixed $default = null) 获取缓存值
 * @method static mixed get(string $key, mixed $default = null) Get cached value
 * @method static bool set(string $key, mixed $value, int $ttl = 3600) 设置缓存值
 * @method static bool set(string $key, mixed $value, int $ttl = 3600) Set cached value
 * @method static bool delete(string $key) 删除缓存
 * @method static bool delete(string $key) Delete cache
 * @method static bool has(string $key) 检查缓存是否存在
 * @method static bool has(string $key) Check if cache exists
 * @method static mixed remember(string $key, int $ttl, Closure $callback) 记住缓存值
 * @method static mixed remember(string $key, int $ttl, Closure $callback) Remember cached value
 * @method static int increment(string $key, int $value = 1) 递增缓存值
 * @method static int increment(string $key, int $value = 1) Increment cached value
 * @method static int decrement(string $key, int $value = 1) 递减缓存值
 * @method static int decrement(string $key, int $value = 1) Decrement cached value
 * @method static mixed pull(string $key, mixed $default = null) 拉取缓存值（获取后删除）
 * @method static mixed pull(string $key, mixed $default = null) Pull cached value (get and delete)
 * @method static bool forget(string $key) 删除缓存（别名）
 * @method static bool forget(string $key) Delete cache (alias)
 * @method static bool clear() 清除所有缓存
 * @method static bool clear() Clear all cache
 * @method static bool supports(string $feature) 检查缓存驱动是否支持某个功能
 * @method static bool supports(string $feature) Check if cache driver supports a feature
 */
class Cache extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'cache';
    }
}