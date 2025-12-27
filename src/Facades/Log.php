<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 日志门面类
 * 使用方式：Log::info('message'), Log::error('error')
 * 
 * @method static void emergency(string $message, array $context = []) 记录紧急级别的日志
 * @method static void alert(string $message, array $context = []) 记录警报级别的日志
 * @method static void critical(string $message, array $context = []) 记录严重级别的日志
 * @method static void error(string $message, array $context = []) 记录错误级别的日志
 * @method static void warning(string $message, array $context = []) 记录警告级别的日志
 * @method static void notice(string $message, array $context = []) 记录通知级别的日志
 * @method static void info(string $message, array $context = []) 记录信息级别的日志
 * @method static void debug(string $message, array $context = []) 记录调试级别的日志
 * @method static void log(int|string $level, string $message, array $context = []) 记录指定级别的日志
 * @method static void setRequestData(array $data) 设置请求数据
 * @method static void setRequestStartTime(float $time) 设置请求开始时间
 * @method static void recordAutoLog(string $clientIp, string $serverIp, string $uri, string $userAgent, int $statusCode = 200) 记录自动日志
 * @method static void recordManualLogs(string $clientIp, string $serverIp, string $uri, string $userAgent) 记录手动日志
 * @method static void recordErrorLog(string $clientIp, string $serverIp, string $uri, string $userAgent, string $errorMessage, string $stackTrace = '') 记录错误日志
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