<?php

namespace PHPFrame;

class Container
{
    private static $instance = null;
    protected $services = [];
    protected $config = [];
    protected $instances = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->registerCoreServices();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function make($id)
    {
        return self::getInstance()->get($id);
    }

    public function set($id, $service)
    {
        $this->services[$id] = $service;
    }

    public function get($id)
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->services[$id])) {
            if (is_callable($this->services[$id])) {
                $instance = $this->services[$id]($this);
                $this->instances[$id] = $instance;
                return $instance;
            }
            $this->instances[$id] = $this->services[$id];
            return $this->instances[$id];
        }

        if (class_exists($id)) {
            $instance = $this->autoRegister($id);
            $this->instances[$id] = $instance;
            return $instance;
        }

        if (interface_exists($id)) {
            $instance = $this->resolveInterface($id);
            $this->instances[$id] = $instance;
            return $instance;
        }

        throw new \Exception("Service not found: {$id}");
    }

    public function has($id)
    {
        return isset($this->services[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /**
     * 注册核心服务（db、cache、config、log等）
     */
    protected function registerCoreServices()
    {
        // 配置服务
        $this->services['config'] = function () {
            $appConfig = $this->loadConfig(CONFIG_PATH . '/app.php');
            return new ConfigManager(array_merge([
                'app' => $appConfig,
                'cache' => $this->loadConfig(CONFIG_PATH . '/cache.php'),
                'database' => $this->loadConfig(CONFIG_PATH . '/database.php'),
                'exception' => $this->loadConfig(CONFIG_PATH . '/exception.php'),
            ], $appConfig['config_map'] ?? []));
        };

        // 路由服务
        $this->services['router'] = function () {
            return \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
                // 设置RouteCollector实例，以便路由文件可以使用Route::get()等方法
                Facades\Route::setCollector($r);

                // 加载所有路由文件
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

            $isReactPHP = PHP_SAPI === 'cli' && isset($_SERVER['argv']) && in_array('--react', $_SERVER['argv'] ?? [], true);
            $usePersistent = ($_ENV['DB_PERSISTENT'] ?? 'false') === 'true' || $isReactPHP;

            if ($usePersistent) {
                $dbManager->enablePersistentConnections(true);
            }

            $dbManager->setRuntimeMode($isReactPHP ? 'react' : 'fpm');

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
            $logConfig = $this->loadConfig(CONFIG_PATH . '/log.php');
            return Logger::getInstance($logConfig);
        };

        // Twig模板引擎
        $this->services['twig'] = function () {
            $loader = new \Twig\Loader\FilesystemLoader(ROOT_PATH . '/resources/templates');
            $twig = new \Twig\Environment($loader, [
                'cache' => RUNTIME_PATH . '/cache/views',
                'debug' => $_ENV['APP_DEBUG'] ?? false,
                'auto_reload' => true,
            ]);

            // 添加全局变量
            $twig->addGlobal('current_uri', function () {
                // 获取当前URI，兼容FPM和CLI模式
                if (isset($_SERVER['REQUEST_URI'])) {
                    $uri = $_SERVER['REQUEST_URI'];
                    // 去除查询字符串
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

        // 数据库管理器服务
        $this->services['PHPFrame\Database\DatabaseManager'] = function ($c) {
            $capsule = $this->get('capsule');
            return new Database\DatabaseManager($capsule);
        };

        // 缓存管理器服务
        $this->services['PHPFrame\CacheManager'] = function ($c) {
            return $c->get('cache');
        };

        // 请求服务
        $this->services['request'] = function () {
            static $instance = null;
            if ($instance === null) {
                $instance = new Request();
            }
            return $instance;
        };

        // 应用服务（用于App门面）
        $this->services['app'] = function ($c) {
            return new class($c) {
                private $container;

                public function __construct($container)
                {
                    $this->container = $container;
                }

                public function environment()
                {
                    return $_ENV['APP_ENV'] ?? 'production';
                }

                public function isDebug()
                {
                    return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
                }

                public function getContainer()
                {
                    return $this->container;
                }
            };
        };
    }

    private function autoRegister($className)
    {
        if (class_exists($className)) {
            $reflection = new \ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                if ($reflection->isInterface()) {
                    return $this->resolveInterface($className);
                }
                throw new \Exception("Cannot instantiate abstract class or interface: {$className}");
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

        $serviceConfig = $this->config['services'][$className] ?? null;
        if ($serviceConfig) {
            return $this->registerFromConfig($className, $serviceConfig);
        }

        if (strpos($className, 'App\\') === 0 || strpos($className, 'PHPFrame\\') === 0) {
            $reflection = new \ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                if ($reflection->isInterface()) {
                    return $this->resolveInterface($className);
                }
                throw new \Exception("Cannot instantiate abstract class or interface: {$className}");
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
                    }
                }
            }

            return $reflection->newInstanceArgs($dependencies);
        }

        throw new \Exception("Service not found and cannot auto-register: {$className}");
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

    public function registerFromServicesConfig()
    {
        $servicesConfig = $this->config['services'] ?? [];

        foreach ($servicesConfig as $id => $config) {
            if ($id === 'auto_register' || $id === 'service_providers') continue;

            if (!isset($this->instances[$id]) && !isset($this->services[$id])) {
                $this->registerFromConfig($id, $config);
            }
        }
    }

    public function registerFromServiceProviders($group = null)
    {
        $providers = $this->config['service_providers'] ?? [];

        if ($group && isset($providers[$group])) {
            foreach ($providers[$group] as $serviceId) {
                if (!isset($this->instances[$serviceId])) {
                    $this->get($serviceId);
                }
            }
        } elseif (!$group) {
            foreach ($providers as $groupServices) {
                foreach ($groupServices as $serviceId) {
                    if (!isset($this->instances[$serviceId])) {
                        $this->get($serviceId);
                    }
                }
            }
        }
    }

    public function getRegisteredServices(): array
    {
        return array_merge(array_keys($this->services), array_keys($this->instances));
    }

    public function getServiceProviderGroups(): array
    {
        return array_keys($this->config['service_providers'] ?? []);
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

    private function loadConfig($configPath)
    {
        if (!file_exists($configPath)) {
            return [];
        }

        return require $configPath;
    }
}