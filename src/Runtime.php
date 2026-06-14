<?php

namespace PHPFrame;

/**
 * 运行模式检测工具类
 * 统一管理FPM、CLI、Shell三种运行模式的检测
 */
class Runtime
{
    /**
     * 当前运行模式常量
     */
    public const MODE_FPM = 'fpm';
    public const MODE_CLI = 'cli';
    public const MODE_SHELL = 'shell';

    /**
     * 缓存检测结果，避免重复计算
     */
    protected static ?string $detectedMode = null;

    /**
     * 检测当前运行模式
     */
    public static function detect(): string
    {
        if (self::$detectedMode !== null) {
            return self::$detectedMode;
        }

        // 优先检查定义的常量
        if (defined('APP_MODE')) {
            return self::$detectedMode = APP_MODE;
        }

        // 根据PHP_SAPI判断
        $sapi = PHP_SAPI;

        switch ($sapi) {
            case 'fpm-fcgi':
            case 'apache2handler':
            case 'cgi-fcgi':
                return self::$detectedMode = self::MODE_FPM;

            case 'cli':
                // 区分CLI和Shell模式
                global $argv;
                if (isset($argv[0]) && basename($argv[0]) === 'shell.php') {
                    return self::$detectedMode = self::MODE_SHELL;
                }
                return self::$detectedMode = self::MODE_CLI;

            default:
                return self::$detectedMode = self::MODE_FPM; // 默认为FPM模式
        }
    }

    /**
     * 重置检测缓存（用于模式切换场景，如测试）
     */
    public static function resetDetection(): void
    {
        self::$detectedMode = null;
    }
    
    /**
     * 是否为FPM模式
     */
    public static function isFpm(): bool
    {
        return self::detect() === self::MODE_FPM;
    }
    
    /**
     * 是否为CLI模式
     */
    public static function isCli(): bool
    {
        return self::detect() === self::MODE_CLI;
    }
    
    /**
     * 是否为Shell模式
     */
    public static function isShell(): bool
    {
        return self::detect() === self::MODE_SHELL;
    }
    
    /**
     * 是否为Web模式（FPM或CLI服务器模式）
     */
    public static function isWeb(): bool
    {
        return self::isFpm() || self::isCli();
    }
    
    /**
     * 是否为命令行模式（CLI或Shell）
     */
    public static function isCommandLine(): bool
    {
        return self::isCli() || self::isShell();
    }
    
    /**
     * 获取当前模式描述
     */
    public static function getDescription(): string
    {
        switch (self::detect()) {
            case self::MODE_FPM:
                return 'FPM Web模式';
            case self::MODE_CLI:
                return 'CLI服务器模式';
            case self::MODE_SHELL:
                return 'Shell命令行模式';
            default:
                return '未知模式';
        }
    }
}