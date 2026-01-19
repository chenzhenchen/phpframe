<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 应用门面类
 * Application Facade Class
 * 使用方式：App::env(), App::isDebug(), App::uri(), App::method()
 * Usage: App::env(), App::isDebug(), App::uri(), App::method()
 * 
 * @method static self getInstance() 获取容器实例
 * @method static self getInstance() Get container instance
 * @method static mixed make($id) 创建服务实例
 * @method static mixed make($id) Create service instance
 * @method static void set($id, $service) 设置服务
 * @method static void set($id, $service) Set service
 * @method static mixed get($id) 获取服务
 * @method static mixed get($id) Get service
 * @method static bool has($id) 检查服务是否存在
 * @method static bool has($id) Check if service exists
 * @method static array getRegisteredServices() 获取已注册的服务列表
 * @method static array getRegisteredServices() Get list of registered services
 * @method static string env() 获取应用环境
 * @method static string env() Get application environment
 * @method static bool isDebug() 检查是否为调试模式
 * @method static bool isDebug() Check if debug mode is enabled
 */
class App extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'app';
    }
}