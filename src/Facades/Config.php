<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 配置门面类
 * 使用方式：Config::get('app.name')
 * 
 * @method static mixed get(string $key, mixed $default = null) 获取配置值
 * @method static array all() 获取所有配置
 * @method static bool has(string $key) 检查配置项是否存在
 */
class Config extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'config';
    }
}