<?php

namespace PHPFrame;

class Container
{
    protected $services = [];
    protected $config = [];
    protected $instances = [];

    /**
     * 原型服务ID列表（每次解析返回新实例）
     */
    protected $prototypes = [];

    /**
     * 当前正在解析的服务ID映射（用于循环依赖检测，O(1)查找）
     */
    protected array $resolutionMap = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 获取容器实例（委托给Application）
     */
    public static function getInstance(): static
    {
        if (Application::hasInstance()) {
            return Application::getInstance();
        }

        // 兜底：如果Application未初始化，创建一个独立Container
        // 这种情况理论上不应发生，仅为向后兼容
        static $fallback = null;
        if ($fallback === null) {
            $fallback = new static();
        }
        return $fallback;
    }

    /**
     * 快速解析服务（静态快捷方式）
     */
    public static function make($id)
    {
        return static::getInstance()->get($id);
    }

    public function set($id, $service)
    {
        $this->services[$id] = $service;
    }

    public function get($id)
    {
        // 循环依赖检测（O(1)查找）
        if (isset($this->resolutionMap[$id])) {
            $chain = implode(' → ', array_keys($this->resolutionMap)) . ' → ' . $id;
            throw new \Exception("Circular dependency detected: {$chain}");
        }

        // 非原型服务：优先返回已缓存实例
        if (!isset($this->prototypes[$id]) && isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->services[$id])) {
            $this->resolutionMap[$id] = true;
            try {
                if (is_callable($this->services[$id])) {
                    $instance = $this->services[$id]($this);
                } else {
                    $instance = $this->services[$id];
                }
            } finally {
                unset($this->resolutionMap[$id]);
            }

            // 原型服务不缓存实例
            if (!isset($this->prototypes[$id])) {
                $this->instances[$id] = $instance;
            }
            return $instance;
        }

        if (class_exists($id)) {
            $this->resolutionMap[$id] = true;
            try {
                $instance = $this->autoRegister($id);
            } finally {
                unset($this->resolutionMap[$id]);
            }

            if (!isset($this->prototypes[$id])) {
                $this->instances[$id] = $instance;
            }
            return $instance;
        }

        if (interface_exists($id)) {
            $this->resolutionMap[$id] = true;
            try {
                $instance = $this->resolveInterface($id);
            } finally {
                unset($this->resolutionMap[$id]);
            }

            if (!isset($this->prototypes[$id])) {
                $this->instances[$id] = $instance;
            }
            return $instance;
        }

        throw new \Exception("Service not found: {$id}");
    }

    public function has($id)
    {
        return isset($this->services[$id]) || isset($this->instances[$id]);
    }

    /**
     * 注册原型服务（每次解析返回新实例）
     */
    public function prototype($id, callable $factory)
    {
        $this->services[$id] = $factory;
        $this->prototypes[$id] = true;
    }

    /**
     * 移除已注册的服务或实例
     */
    public function unset($id)
    {
        unset($this->services[$id], $this->instances[$id], $this->prototypes[$id]);
    }

    /**
     * 注册核心服务
     * 仅由Application::initialize()调用一次
     */
    protected function registerCoreServices()
    {
        // 配置服务 — 自动扫描 config/ 目录
        $this->services['config'] = function () {
            $environment = $_ENV['APP_ENV'] ?? 'production';
            return new ConfigManager([], CONFIG_PATH, $environment);
        };

        // 路由服务
        $this->services['router'] = function () {
            return \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
                Facades\Route::setCollector($r);

                $routeFiles = glob(ROUTES_PATH . '/*.php');
                foreach ($routeFiles as $routeFile) {
                    require $routeFile;
                }
            });
        };

        // 数据库服务
        $this->services['db'] = function ($c) {
            $config = $c->get('config')->get('database');
            $capsule = new \Illuminate\Database\Capsule\Manager;

            foreach ($config['connections'] as $name => $connectionConfig) {
                $capsule->addConnection($connectionConfig, $name);
            }

            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            $dbManager = new Database\DatabaseManager($capsule);

            $appMode = defined('APP_MODE') ? APP_MODE : 'fpm';
            $usePersistent = ($appMode === 'cli') || ($_ENV['DB_PERSISTENT'] ?? 'false') === 'true';

            if ($usePersistent) {
                $dbManager->enablePersistentConnections(true);
            }

            // 自动注入 CacheManager，使查询缓存可跨进程/跨请求共享
            try {
                $cacheManager = $c->get('cache');
                $dbManager->setCacheManager($cacheManager);
            } catch (\Exception $e) {
                // CacheManager 不可用时降级到进程内数组缓存
            }

            return $dbManager;
        };

        // Redis管理器
        $this->services['redis.manager'] = function ($c) {
            $config = $c->get('config')->get('cache.stores.redis');
            return new \Illuminate\Redis\RedisManager(null, $config['client'], [
                'default' => [
                    'host' => $config['host'],
                    'password' => $config['password'],
                    'port' => $config['port'],
                    'database' => $config['database'] ?? 0,
                ],
            ]);
        };

        // Redis服务（用于Redis门面）
        $this->services['redis'] = function ($c) {
            $redisManager = $c->get('redis.manager');
            return $redisManager->connection();
        };

        // 缓存服务
        $this->services['cache'] = function ($c) {
            $config = $c->get('config')->get('cache');
            $driver = $config['default'];

            switch ($driver) {
                case 'redis':
                    $redisManager = $c->get('redis.manager');
                    $store = new \Illuminate\Cache\RedisStore($redisManager);
                    break;
                case 'file':
                    $store = new \Illuminate\Cache\FileStore(new \Illuminate\Filesystem\Filesystem(), RUNTIME_PATH . '/cache');
                    break;
                default:
                    $store = new \Illuminate\Cache\ArrayStore();
                    break;
            }

            $repository = new \Illuminate\Cache\Repository($store);
            return new CacheManager($repository);
        };

        // 日志服务
        $this->services['logger'] = function () {
            $logFile = CONFIG_PATH . '/log.php';
            $logConfig = file_exists($logFile) ? require $logFile : [];
            return Logger::getInstance($logConfig);
        };

        // Twig模板引擎
        $this->services['twig'] = function () {
            $loader = new \Twig\Loader\FilesystemLoader(resource_path('templates'));
            $twig = new \Twig\Environment($loader, [
                'cache' => runtime_path('cache/views'),
                'debug' => $_ENV['APP_DEBUG'] ?? false,
                'auto_reload' => true,
            ]);

            $twig->addGlobal('current_uri', function () {
                if (isset($_SERVER['REQUEST_URI'])) {
                    $uri = $_SERVER['REQUEST_URI'];
                    if (false !== $pos = strpos($uri, '?')) {
                        $uri = substr($uri, 0, $pos);
                    }
                    return rawurldecode($uri);
                }
                return '/';
            });

            return $twig;
        };

        // 哈希服务
        $this->services['hash'] = new \Illuminate\Hashing\BcryptHasher();

        // 请求服务（FPM模式单例，CLI模式每次创建新实例避免状态污染）
        $this->services['request'] = function () {
            return Request::createFromGlobals();
        };

        // CLI常驻内存模式下，request 应为原型服务（每次解析返回新实例）
        if (defined('APP_MODE') && APP_MODE === 'cli') {
            $this->prototypes['request'] = true;
        }


    }

    private function autoRegister($className)
    {
        // 先尝试从配置注册
        $serviceConfig = $this->config['services'][$className] ?? null;
        if ($serviceConfig) {
            return $this->registerFromConfig($className, $serviceConfig);
        }

        // 自动解析类
        if (!class_exists($className)) {
            throw new \Exception("Service not found and cannot auto-register: {$className}");
        }

        $reflection = new \ReflectionClass($className);

        if ($reflection->isAbstract()) {
            throw new \Exception("Cannot instantiate abstract class: {$className}");
        }

        if ($reflection->isInterface()) {
            return $this->resolveInterface($className);
        }

        $constructor = $reflection->getConstructor();
        $dependencies = [];

        if ($constructor) {
            foreach ($constructor->getParameters() as $parameter) {
                $paramType = $parameter->getType();

                if ($paramType && !$paramType->isBuiltin()) {
                    $dependencies[] = $this->get($paramType->getName());
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new \Exception("Cannot resolve parameter '{$parameter->getName()}' for class {$className}");
                }
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    private function registerFromConfig($id, $config)
    {
        if (isset($config['factory']) && is_callable($config['factory'])) {
            $service = $config['factory']($this);
        } elseif (isset($config['class'])) {
            $dependencies = $config['dependencies'] ?? [];
            $args = [];
            foreach ($dependencies as $dependency) {
                $args[] = $this->get($dependency);
            }
            $service = new $config['class'](...$args);
        } else {
            throw new \Exception("Invalid service configuration for: {$id}");
        }

        $this->instances[$id] = $service;
        return $service;
    }

    public function getRegisteredServices(): array
    {
        return array_merge(array_keys($this->services), array_keys($this->instances));
    }

    private function resolveInterface($interfaceName)
    {
        $interfaceMappings = [
            'Illuminate\\Contracts\\Hashing\\Hasher' => 'hash',
            'Illuminate\\Contracts\\Cache\\Repository' => 'cache',
            'Illuminate\\Contracts\\Redis\\Factory' => 'redis.manager',
            'Illuminate\\Database\\ConnectionInterface' => 'db',
            'Psr\\Log\\LoggerInterface' => 'logger',
        ];

        if (isset($interfaceMappings[$interfaceName])) {
            return $this->get($interfaceMappings[$interfaceName]);
        }

        $services = $this->config['services'] ?? [];
        foreach ($services as $serviceId => $config) {
            if (isset($config['class']) && class_exists($config['class'])) {
                $implementation = new \ReflectionClass($config['class']);
                if ($implementation->implementsInterface($interfaceName)) {
                    return $this->get($serviceId);
                }
            }
        }

        throw new \Exception("Cannot resolve interface: {$interfaceName}");
    }

}
