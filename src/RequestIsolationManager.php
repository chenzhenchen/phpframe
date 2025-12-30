<?php

namespace PHPFrame;

/**
 * 请求隔离管理器
 * Request Isolation Manager
 * 
 * 统一管理常驻内存模式下的请求级状态隔离
 * Unified management of request-level state isolation in persistent memory mode
 * 确保每个请求都有独立的状态，避免状态污染
 * Ensures each request has independent state to avoid state pollution
 */
class RequestIsolationManager
{
    /**
     * 需要隔离的服务列表
     * List of services that need isolation
     *
     * @var array
     */
    protected static array $isolatableServices = [
        'db' => [
            'class' => \PHPFrame\Database\DatabaseManager::class,
            'methods' => ['clearQueryLog', 'flushQueryLog'],
            'description' => '数据库查询日志'
        ],
        'request' => [
            'class' => \PHPFrame\Request::class,
            'methods' => ['clearParams'],
            'description' => '请求参数'
        ]
    ];

    /**
     * 是否已初始化
     * Whether initialized
     *
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * 注册需要隔离的服务
     * Register service that needs isolation
     *
     * @param string $serviceId 服务ID Service ID
     * @param string $class 服务类名 Service class name
     * @param array $methods 隔离方法列表 Isolation method list
     * @param string $description 服务描述 Service description
     * @return void
     */
    public static function registerService(
        string $serviceId, 
        string $class, 
        array $methods = [], 
        string $description = ''
    ): void {
        self::$isolatableServices[$serviceId] = [
            'class' => $class,
            'methods' => $methods,
            'description' => $description
        ];
    }

    /**
     * 注销服务
     * Unregister service
     *
     * @param string $serviceId 服务ID Service ID
     * @return void
     */
    public static function unregisterService(string $serviceId): void
    {
        unset(self::$isolatableServices[$serviceId]);
    }

    /**
     * 获取所有已注册的服务
     * Get all registered services
     *
     * @return array
     */
    public static function getServices(): array
    {
        return self::$isolatableServices;
    }

    /**
     * 执行所有隔离操作
     * Execute all isolation operations
     *
     * @return array 执行结果 Execution results
     */
    public static function isolateAll(): array
    {
        $results = [];
        
        foreach (self::$isolatableServices as $serviceId => $config) {
            $results[$serviceId] = self::isolateService($serviceId, $config);
        }
        
        self::$initialized = true;
        
        return $results;
    }

    /**
     * 执行单个服务的隔离操作
     * Execute isolation operation for a single service
     *
     * @param string $serviceId 服务ID Service ID
     * @param array $config 服务配置 Service configuration
     * @return array
     */
    private static function isolateService(string $serviceId, array $config): array
    {
        $result = [
            'service' => $serviceId,
            'class' => $config['class'],
            'description' => $config['description'] ?? '',
            'status' => 'pending',
            'methods_called' => [],
            'error' => null
        ];

        try {
            $container = Container::getInstance();
            
            if (!$container->has($serviceId)) {
                $result['status'] = 'skipped';
                $result['error'] = 'Service not found in container';
                return $result;
            }

            $instance = $container->get($serviceId);
            
            if (!$instance instanceof $config['class']) {
                $result['status'] = 'skipped';
                $result['error'] = 'Instance type mismatch';
                return $result;
            }

            foreach ($config['methods'] as $method) {
                if (method_exists($instance, $method)) {
                    $instance->$method();
                    $result['methods_called'][] = $method;
                }
            }

            $result['status'] = 'success';
            
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 隔离单个服务
     *
     * @param string $serviceId 服务ID
     * @return bool
     */
    public static function isolate(string $serviceId): bool
    {
        if (!isset(self::$isolatableServices[$serviceId])) {
            return false;
        }

        $result = self::isolateService($serviceId, self::$isolatableServices[$serviceId]);
        return $result['status'] === 'success';
    }

    /**
     * 检查是否已初始化
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * 重置状态（主要用于测试）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$initialized = false;
    }

    /**
     * 获取隔离报告
     *
     * @return array
     */
    public static function getReport(): array
    {
        return [
            'initialized' => self::$initialized,
            'services_count' => count(self::$isolatableServices),
            'services' => array_map(function($service) {
                return [
                    'id' => $service['description'] ?? 'Unknown',
                    'methods' => count($service['methods'])
                ];
            }, self::$isolatableServices)
        ];
    }
}
