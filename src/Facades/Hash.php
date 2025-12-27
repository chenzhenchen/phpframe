<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 哈希门面类
 * 使用方式：Hash::make('password'), Hash::check('password', '$2y$10$...')
 * 
 * @method static string make(string $value, array $options = []) 生成密码哈希
 * @method static bool check(string $value, string $hashedValue, array $options = []) 验证密码哈希
 * @method static bool needsRehash(string $hashedValue, array $options = []) 检查哈希是否需要重新生成
 */
class Hash extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'hash';
    }
}