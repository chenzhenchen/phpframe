<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 应用门面类
 * Application Facade Class
 * 使用方式：App::get('db'), App::has('cache'), App::set('my', fn() => new MyService())
 * 
 * @method static self getInstance() 获取容器实例
 * @method static mixed make($id) 创建服务实例
 * @method static void set($id, $service) 设置服务
 * @method static mixed get($id) 获取服务
 * @method static bool has($id) 检查服务是否存在
 * @method static array getRegisteredServices() 获取已注册的服务列表
 */
class App extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'app';
    }
}