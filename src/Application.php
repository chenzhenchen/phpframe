<?php

namespace PHPFrame;

use PHPFrame\RouteManager;
use PHPFrame\Reactive\ReactiveServerManager;

/**
 * 应用类
 */
class Application extends Container
{
    /**
     * 应用实例
     */
    protected static ?Application $instance = null;

    /**
     * 应用是否已经初始化
     */
    protected bool $booted = false;

    /**
     * 全局初始化标志（防止常量重复定义等）
     */
    protected static bool $globalInitialized = false;

    /**
     * 请求开始时间
     */
    protected float $requestStartTime;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->requestStartTime = microtime(true);
        static::setInstance($this);

        // 初始化应用
        $this->initialize();
    }

    /**
     * 获取应用实例
     */
    public static function getInstance(): static
    {
        if (is_null(static::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * 检查应用实例是否存在
     */
    public static function hasInstance(): bool
    {
        return static::$instance !== null;
    }

    /**
     * 设置应用实例
     */
    protected static function setInstance(Application $instance)
    {
        static::$instance = $instance;
    }

    /**
     * 初始化应用
     */
    protected function initialize()
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // 全局初始化（常量、环境变量等，仅需执行一次）
        if (!static::$globalInitialized) {
            static::$globalInitialized = true;

            // 定义框架常量
            if (!defined('RUNTIME_PATH')) {
                define('RUNTIME_PATH', ROOT_PATH . '/runtime');
            }
            if (!defined('CONFIG_PATH')) {
                define('CONFIG_PATH', ROOT_PATH . '/config');
            }
            if (!defined('ROUTES_PATH')) {
                define('ROUTES_PATH', ROOT_PATH . '/routes');
            }

            // 加载环境变量
            $this->loadEnvironment();

            // 设置时区
            date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Shanghai');

            // 创建必要的目录
            $requiredDirs = [
                RUNTIME_PATH . '/logs',
                RUNTIME_PATH . '/cache',
            ];

            foreach ($requiredDirs as $dir) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }
        }

        // 注册核心服务（仅此一处调用）
        $this->registerCoreServices();

        // 注册异常处理器
        // 优先使用用户自定义处理器（config/exception.php），否则使用框架内置处理器
        $exceptionHandlerClass = config('exception.handler') ?? Exceptions\ExceptionHandler::class;

        $this->set($exceptionHandlerClass, function ($c) use ($exceptionHandlerClass) {
            return new $exceptionHandlerClass(
                $c->has('logger') ? $c->get('logger') : null,
                $c->has('config') ? $c->get('config')->get('exception', []) : []
            );
        });

        set_exception_handler(function (\Throwable $exception) use ($exceptionHandlerClass) {
            try {
                $handler = $this->get($exceptionHandlerClass);
                $mode = PHP_SAPI === 'cli' ? 'cli' : 'fpm';
                $response = $handler->handle($exception, $mode);

                // FPM 模式下输出响应
                if ($mode === 'fpm') {
                    if (is_array($response) || is_object($response)) {
                        if (!headers_sent()) {
                            header('Content-Type: application/json');
                        }
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    } else {
                        echo $response;
                    }
                }
            } catch (\Throwable $e) {
                // 异常处理器自身出错时的兜底
                error_log("ExceptionHandler failed: " . $e->getMessage());
                if (PHP_SAPI !== 'cli') {
                    http_response_code(500);
                    echo json_encode(['error' => 'Internal Server Error']);
                }
            }
        });

        // 提前解析 db 服务，确保 Eloquent 连接解析器已设置
        // 否则 Eloquent Model 在 db 服务未解析时会因缺少 resolver 而报错
        try {
            $this->get('db');
        } catch (\Throwable $e) {
            // 数据库不可用时不阻塞应用启动（如仅使用缓存/文件等场景）
        }
    }

    /**
     * 加载环境变量
     * 优先使用 vlucas/phpdotenv，降级到手动解析
     */
    protected function loadEnvironment()
    {
        $envFile = ROOT_PATH . '/.env';

        if (!file_exists($envFile)) {
            return;
        }

        // 优先使用 phpdotenv
        if (class_exists(\Dotenv\Dotenv::class)) {
            static $envLoaded = false;
            if (!$envLoaded) {
                $dotenv = \Dotenv\Dotenv::createImmutable(ROOT_PATH);
                $dotenv->safeLoad();
                $envLoaded = true;
            }
            return;
        }

        // 降级：手动解析 .env 文件
        static $envLoaded = false;
        if ($envLoaded) {
            return;
        }

        $envContent = file_get_contents($envFile);
        $lines = explode("\n", $envContent);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // 移除引号
                if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match('/^\'(.*)\'$/', $value, $matches)) {
                    $value = $matches[1];
                }

                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
        $envLoaded = true;
    }

    /**
     * 运行应用
     */
    public function run()
    {
        $mode = APP_MODE ?? 'fpm';

        switch ($mode) {
            case 'fpm':
                $this->runFpm();
                break;
            case 'cli':
                $this->runCli();
                break;
            case 'shell':
                $this->runShell();
                break;
            default:
                throw new \Exception("Unsupported application mode: {$mode}");
        }
    }

    /**
     * 获取服务器IP地址
     */
    protected function getServerIp(): string
    {
        if (isset($_SERVER['SERVER_ADDR'])) {
            return $_SERVER['SERVER_ADDR'];
        }
        if (isset($_SERVER['LOCAL_ADDR'])) {
            return $_SERVER['LOCAL_ADDR'];
        }
        $ip = '127.0.0.1';
        if (strtoupper(PHP_OS) === 'LINUX') {
            $ip = trim(exec('hostname -I'));
            if ($ip) {
                return explode(' ', $ip)[0];
            }
        }
        return $ip;
    }

    /**
     * 获取客户端IP地址
     */
    protected function getClientIp(): string
    {
        $request = $this->get('request');
        return $request->getClientIp();
    }

    /**
     * 获取User Agent
     */
    protected function getUserAgent(): string
    {
        if (Runtime::isFpm()) {
            return $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        return '';
    }

    /**
     * 运行FPM模式
     * 通过 Request 对象获取请求信息，避免直接操作超全局变量
     */
    protected function runFpm()
    {
        $request = $this->get('request');

        $httpMethod = $request->server('REQUEST_METHOD', 'GET');

        $uri = $request->server('REQUEST_URI', '/');

        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        if ($uri === '/index.php') {
            $uri = '/';
        }

        $dispatcher = $this->get('router');
        $routeManager = new RouteManager($dispatcher, $this);

        // 将路由注册阶段暂存的中间件应用到 RouteManager
        Facades\Route::applyPendingMiddlewares($routeManager);

        // 注册 RouteManager 到容器，使 CLI 模式可复用（保留中间件注册）
        $this->instances['route_manager'] = $routeManager;

        try {
            $response = $routeManager->handleFpmRequest($httpMethod, $uri, $this, $this->requestStartTime);

            if (is_array($response) || is_object($response)) {
                header('Content-Type: application/json');
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                echo $response;
            }

        } catch (\Throwable $e) {
            // 使用统一的异常处理器
            $exceptionHandlerClass = config('exception.handler') ?? Exceptions\ExceptionHandler::class;

            try {
                $handler = $this->get($exceptionHandlerClass);
                $response = $handler->handle($e, 'fpm');
            } catch (\Throwable $handlerError) {
                $response = ['error' => 'Internal Server Error', 'status_code' => 500];
            }

            if (is_array($response) || is_object($response)) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                    http_response_code(500);
                }
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo $response;
            }
        }
    }

    /**
     * 运行CLI模式
     */
    protected function runCli()
    {
        global $argv;
        if (!isset($argv)) {
            $argv = $_SERVER['argv'] ?? [];
        }

        $command = $argv[1] ?? null;
        $args = array_slice($argv, 2);

        if (!$command) {
            echo "Usage: php cli.php [command] [args...]" . PHP_EOL;
            echo "Available commands:" . PHP_EOL;
            echo "  server start [--host=0.0.0.0] [--port=8000]  Start server" . PHP_EOL;
            echo "  server stop     Stop server" . PHP_EOL;
            echo "  server reload   Reload server config" . PHP_EOL;
            echo "  server restart  Restart server" . PHP_EOL;
            echo "  server status   Show server status" . PHP_EOL;
            exit(1);
        }

        try {
            if ($command === 'server') {
                $serverAction = $args[0] ?? 'start';
                $serverArgs = array_slice($args, 1);

                $host = '0.0.0.0';
                $port = 8000;
                $worker = 4;
                $deamon = false;

                foreach ($serverArgs as $i => $arg) {
                    if (strpos($arg, '--host=') === 0) {
                        $host = substr($arg, 7);
                        unset($serverArgs[$i]);
                    } elseif (strpos($arg, '--port=') === 0) {
                        $port = (int)substr($arg, 7);
                        unset($serverArgs[$i]);
                    } elseif (strpos($arg, '--worker=') === 0) {
                        $worker = (int)substr($arg, 9);
                        unset($serverArgs[$i]);
                    } elseif (strpos($arg, '--deamon=') === 0) {
                        $deamon = substr($arg, 9);
                        if (strtolower($deamon) === 'true' || (int)$deamon === 1) {
                            $deamon = true;
                        }
                        unset($serverArgs[$i]);
                    }
                }

                $serverArgs = array_values($serverArgs);

                $serverManager = new ReactiveServerManager($host, $port, $worker, $deamon);

                switch ($serverAction) {
                    case 'start':
                        $serverManager->start();
                        break;
                    case 'run':
                        $serverManager->run();
                        break;
                    case 'stop':
                        $serverManager->stop();
                        break;
                    case 'reload':
                        $serverManager->reload();
                        break;
                    case 'restart':
                        $serverManager->restart();
                        break;
                    case 'status':
                        $status = $serverManager->getStatus();
                        echo "Server status:\n";
                        echo "  Running status: " . ($status['running'] ? 'Running' : 'Not running') . "\n";
                        echo "  Listening address: {$status['host']}:{$status['port']}\n";
                        echo "  Worker process count: {$status['worker_num']}\n";
                        echo "  PID file: {$status['pid_file']}\n";
                        break;
                    default:
                        echo "Unknown server command: {$serverAction}\n";
                        exit(1);
                }

                exit(0);
            }

            echo "Error: Unknown command '{$command}'\n";
            echo "Please use 'php cli.php server start' to start the server, or use 'php shell.php' to execute other commands\n";
            exit(1);

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * 运行Shell模式
     */
    protected function runShell()
    {
        global $argv;
        if (!isset($argv)) {
            $argv = $_SERVER['argv'] ?? [];
        }

        $command = $argv[1] ?? null;
        $args = array_slice($argv, 2);

        if (!$command) {
            echo "Usage: php shell.php controller/action [args...]\n";
            echo "Parameter format:\n";
            echo "  Key-value arguments: php shell.php user/create name=John age=25\n";
            exit(1);
        }

        Facade::setContext(['method' => 'SHELL']);

        $dispatcher = $this->get('router');
        $routeManager = new RouteManager($dispatcher, $this);

        // 将路由注册阶段暂存的中间件应用到 RouteManager
        Facades\Route::applyPendingMiddlewares($routeManager);

        try {
            $routeManager->handleShellRequest($command, $args);
        } finally {
            Facade::clearContext();
        }
    }

    /**
     * 注册核心服务
     * 先注册 app 为自身，再调用父类注册其他核心服务
     */
    protected function registerCoreServices()
    {
        // 将 app 服务指向 Application 实例自身
        $this->instances['app'] = $this;

        parent::registerCoreServices();
    }
}
