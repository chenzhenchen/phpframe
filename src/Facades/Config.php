<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 配置门面类
 * Config Facade Class
 * 使用方式：Config::get('app.name')
 * Usage: Config::get('app.name')
 * 
 * @method static mixed get(string $key, mixed $default = null) 获取配置值
 * @method static mixed get(string $key, mixed $default = null) Get configuration value
 * @method static array all() 获取所有配置
 * @method static array all() Get all configurations
 * @method static bool has(string $key) 检查配置项是否存在
 * @method static bool has(string $key) Check if configuration item exists
 */
class Config extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'config';
    }
}