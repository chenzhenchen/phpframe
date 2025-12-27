<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 应用门面类
 * 使用方式：App::environment(), App::isDebug(), App::uri(), App::method()
 * 
 * @method static self getInstance() 获取容器实例
 * @method static mixed make($id) 创建服务实例
 * @method static void set($id, $service) 设置服务
 * @method static mixed get($id) 获取服务
 * @method static bool has($id) 检查服务是否存在
 * @method static void registerFromServicesConfig() 从配置中注册服务
 * @method static void registerFromServiceProviders(string $group = null) 按服务提供者组注册服务
 * @method static array getRegisteredServices() 获取已注册的服务列表
 * @method static array getServiceProviderGroups() 获取服务提供者组列表
 * @method static string environment() 获取应用环境
 * @method static bool isDebug() 检查是否为调试模式
 */
class App extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'app';
    }
}