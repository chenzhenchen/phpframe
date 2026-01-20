<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 日志门面类
 * 使用方式：Log::info('message'), Log::error('error')
 *
 * @method static void error(string $message, array $context = []) 记录错误级别的日志
 * @method static void warning(string $message, array $context = []) 记录警告级别的日志
 * @method static void notice(string $message, array $context = []) 记录通知级别的日志
 * @method static void info(string $message, array $context = []) 记录信息级别的日志
 * @method static void debug(string $message, array $context = []) 记录调试级别的日志
 * @method static void log(int|string $level, string $message, array $context = []) 记录指定级别的日志
 */
class Log extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'logger';
    }

    public static function getLogger()
    {
        return static::getFacadeRoot();
    }
}