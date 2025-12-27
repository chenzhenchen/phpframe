<?php

use PHPFrame\Container;

if (!defined('PHPFRAME_PATH')) {
    define('PHPFRAME_PATH', __DIR__);
}

if (!defined('ROOT_PATH')) {
    if (!$rootPath = Phar::running()) {
        $rootPath = getcwd();
        while ($rootPath !== dirname($rootPath)) {
            if (@is_dir("$rootPath/vendor")) {
                break;
            }
            $rootPath = dirname($rootPath);
        }
        if ($rootPath === dirname($rootPath)) {
            exit('Please define the ROOT_PATH constant in your public/index.php file.');
        }
    }
    define('ROOT_PATH', realpath($rootPath) ?: $rootPath);
}

if (!function_exists('app')) {
    /**
     * 获取应用容器实例或从容器中解析服务
     * 支持全局调用：app('db'), app('cache'), app('log'), app('config')
     */
    function app($abstract = null)
    {
        if (is_null($abstract)) {
            return Container::getInstance();
        }

        return Container::make($abstract);
    }
}

if (!function_exists('config')) {
    /**
     * 获取配置值
     */
    function config($key = null, $default = null)
    {
        $config = app('config');
        if (is_null($key)) {
            return $config->all();
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * 获取环境变量值
     */
    function env($key, $default = null)
    {
        return $_ENV[$key] ?? $default;
    }
}

if (!function_exists('root_path')) {
    /**
     * 获取应用根目录路径
     */
    function root_path($path = '')
    {
        return ROOT_PATH . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('runtime_path')) {
    /**
     * 获取运行时目录路径
     */
    function runtime_path($path = '')
    {
        return RUNTIME_PATH . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('config_path')) {
    /**
     * 获取配置目录路径
     */
    function config_path($path = '')
    {
        return CONFIG_PATH . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('resource_path')) {
    /**
     * 获取资源目录路径
     */
    function resource_path($path = '')
    {
        return root_path('resources') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('public_path')) {
    /**
     * 获取公共目录路径
     */
    function public_path($path = '')
    {
        return root_path('public') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('database_path')) {
    /**
     * 获取数据库目录路径
     */
    function database_path($path = '')
    {
        return root_path('database') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('array_get')) {
    /**
     * 使用点号表示法从数组中获取值
     */
    function array_get($array, $key, $default = null)
    {
        if (is_null($key)) {
            return $array;
        }

        if (isset($array[$key])) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }
}

if (!function_exists('dd')) {
    /**
     * 调试函数：打印变量并终止脚本
     */
    function dd(...$vars)
    {
        foreach ($vars as $var) {
            var_dump($var);
        }
        die(1);
    }
}

if (!function_exists('logger')) {
    /**
     * 获取日志实例
     */
    function logger()
    {
        return app('logger');
    }
}

if (!function_exists('isolate_request')) {
    /**
     * 执行请求级状态隔离
     * 
     * 在常驻内存模式下，清除上一个请求的残留状态
     * 包括：数据库查询日志、缓存等
     * 
     * @param bool $force 强制执行，即使不在CLI模式
     * @return array 执行结果
     */
    function isolate_request(bool $force = false): array
    {
        if (!$force && !\PHPFrame\Runtime::isCli()) {
            return ['status' => 'skipped', 'reason' => 'Not in CLI mode'];
        }
        
        return \PHPFrame\RequestIsolationManager::isolateAll();
    }
}

if (!function_exists('isolate_service')) {
    /**
     * 隔离单个服务
     * 
     * @param string $serviceId 服务ID
     * @return bool
     */
    function isolate_service(string $serviceId): bool
    {
        return \PHPFrame\RequestIsolationManager::isolate($serviceId);
    }
}

if (!function_exists('register_isolatable_service')) {
    /**
     * 注册需要隔离的服务
     * 
     * @param string $serviceId 服务ID
     * @param string $class 服务类名
     * @param array $methods 隔离方法列表
     * @param string $description 服务描述
     * @return void
     */
    function register_isolatable_service(
        string $serviceId, 
        string $class, 
        array $methods = [], 
        string $description = ''
    ): void {
        \PHPFrame\RequestIsolationManager::registerService(
            $serviceId, 
            $class, 
            $methods, 
            $description
        );
    }
}

if (!function_exists('get_isolation_report')) {
    /**
     * 获取隔离状态报告
     * 
     * @return array
     */
    function get_isolation_report(): array
    {
        return \PHPFrame\RequestIsolationManager::getReport();
    }
}