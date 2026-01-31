<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * Cache Facade Class
 * Usage: Cache::get('key'), Cache::set('key', 'value')
 * 
 * @method static mixed get(string $key, mixed $default = null) Get cached value
 * @method static bool set(string $key, mixed $value, int $ttl = null) Set cached value
 * @method static bool delete(string $key) Delete cache
 * @method static int deleteByPattern(string $pattern) Delete cache by pattern (Redis driver only)
 * @method static bool has(string $key) Check if cache exists
 * @method static mixed remember(string $key, int $ttl, Closure $callback) Remember cached value
 * @method static int increment(string $key, int $value = 1) Increment cached value
 * @method static int decrement(string $key, int $value = 1) Decrement cached value
 * @method static mixed pull(string $key, mixed $default = null) Pull cached value (get and delete)
 * @method static bool forget(string $key) Delete cache (alias)
 * @method static bool clear() Clear all cache
 */
class Cache extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'cache';
    }
}