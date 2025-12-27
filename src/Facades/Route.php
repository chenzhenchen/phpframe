<?php

namespace PHPFrame\Facades;

use FastRoute\RouteCollector;

/**
 * 路由注册门面类
 * 支持类似Laravel的路由注册方式，如Route::get()、Route::post()、Route::shell()等
 * 
 * @method static void get(string $path, callable|array $handler) 注册GET路由
 * @method static void post(string $path, callable|array $handler) 注册POST路由
 * @method static void put(string $path, callable|array $handler) 注册PUT路由
 * @method static void delete(string $path, callable|array $handler) 注册DELETE路由
 * @method static void patch(string $path, callable|array $handler) 注册PATCH路由
 * @method static void options(string $path, callable|array $handler) 注册OPTIONS路由
 * @method static void any(string $path, callable|array $handler) 注册ANY路由（支持所有HTTP方法）
 * @method static void shell(string $path, callable|array $handler) 注册SHELL路由（仅用于shell模式）
 * @method static void group(string $prefix, callable $callback) 注册路由组
 */
class Route
{
    /**
     * @var RouteCollector 当前的RouteCollector实例
     */
    protected static $collector;

    /**
     * 设置当前的RouteCollector实例
     * 
     * @param RouteCollector $collector
     */
    public static function setCollector(RouteCollector $collector): void
    {
        self::$collector = $collector;
    }

    /**
     * 获取当前的RouteCollector实例
     * 
     * @return RouteCollector
     */
    public static function getCollector(): RouteCollector
    {
        return self::$collector;
    }

    /**
     * 注册GET路由
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function get(string $path, $handler): void
    {
        self::addRoute('GET', $path, $handler);
    }

    /**
     * 注册POST路由
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function post(string $path, $handler): void
    {
        self::addRoute('POST', $path, $handler);
    }

    /**
     * 注册PUT路由
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function put(string $path, $handler): void
    {
        self::addRoute('PUT', $path, $handler);
    }

    /**
     * 注册DELETE路由
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function delete(string $path, $handler): void
    {
        self::addRoute('DELETE', $path, $handler);
    }

    /**
     * 注册PATCH路由
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function patch(string $path, $handler): void
    {
        self::addRoute('PATCH', $path, $handler);
    }

    /**
     * 注册OPTIONS路由
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function options(string $path, $handler): void
    {
        self::addRoute('OPTIONS', $path, $handler);
    }

    /**
     * 注册ANY路由（支持所有HTTP方法）
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function any(string $path, $handler): void
    {
        self::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $path, $handler);
    }

    /**
     * 注册SHELL路由（仅用于shell模式）
     * 
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    public static function shell(string $path, $handler): void
    {
        self::addRoute('SHELL', $path, $handler);
    }

    /**
     * 注册路由
     * 
     * @param string|array $methods HTTP方法
     * @param string $path 路由路径
     * @param callable|array $handler 路由处理器
     */
    protected static function addRoute($methods, string $path, $handler): void
    {
        if (!self::$collector) {
            throw new \RuntimeException('Route collector not set. Please call Route::setCollector() first.');
        }

        self::$collector->addRoute($methods, $path, $handler);
    }
    
    /**
     * 注册路由组
     * 
     * @param string $prefix 路由前缀
     * @param callable $callback 路由组回调
     */
    public static function group(string $prefix, callable $callback): void
    {
        if (!self::$collector) {
            throw new \RuntimeException('Route collector not set. Please call Route::setCollector() first.');
        }

        self::$collector->addGroup($prefix, $callback);
    }
}
