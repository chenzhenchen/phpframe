<?php

namespace PHPFrame;

use PHPFrame\Container;
use PHPFrame\RouteManager;
use PHPFrame\Reactive\ReactiveServerManager;

/**
 * 应用类
 * Application class
 */
class Application extends Container
{
    /**
     * 应用实例
     * Application instance
     *
     * @var Application
     */
    protected static $instance;

    /**
     * 应用是否已经初始化
     * Whether the application has been initialized
     *
     * @var bool
     */
    protected $booted = false;

    /**
     * 请求开始时间
     * Request start time
     *
     * @var float
     */
    protected $requestStartTime;

    /**
     * 构造函数
     * Constructor
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
     * Get application instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (is_null(static::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * 设置应用实例
     * Set application instance
     *
     * @param Application $instance
     * @return void
     */
    protected static function setInstance(Application $instance)
    {
        static::$instance = $instance;
    }

    /**
     * 初始化应用
     * Initialize application
     *
     * @return void
     */
    protected function initialize()
    {
        static $initialized = false;
        
        // 确保初始化逻辑只执行一次
        if ($initialized) {
            return;
        }
        $initialized = true;
        
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

        // 加载环境变量（优化性能）
        if (file_exists(ROOT_PATH . '/.env')) {
            static $envLoaded = false;
            
            if (!$envLoaded) {
                $envContent = file_get_contents(ROOT_PATH . '/.env');
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
        }

        // 设置时区
        date_default_timezone_set($_ENV['APP_TIMEZONE']??'Asia/Shanghai');

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
        
        $this->registerCoreServices();

        if ($exceptionHandler = config('exception.handler')) {
            $this->set($exceptionHandler, function($c) use ($exceptionHandler) {
                return new $exceptionHandler(
                    $c->get('logger'),
                    $c->get('config')->get('exception')
                );
            });

            set_exception_handler(function($exception) use ($exceptionHandler) {
                $handler = $this->get($exceptionHandler);
                $mode = PHP_SAPI === 'cli' ? 'cli' : 'fpm';
                return $handler->handle($exception, $mode);
            });
        }
    }


    /**
     * 运行应用
     * Run application
     *
     * @return void
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
     * Get server IP address
     *
     * @return string
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
     * Get client IP address
     *
     * @return string
     */
    protected function getClientIp(): string
    {
        $request = $this->get('request');
        return $request->getClientIp();
    }

    /**
     * 获取User Agent
     * Get user agent
     *
     * @return string
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
     * Run FPM mode
     *
     * @return void
     */
    protected function runFpm()
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH'])) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

            if (strpos($contentType, 'application/json') !== false) {
                $jsonInput = file_get_contents('php://input');
                $jsonData = json_decode($jsonInput, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                    $_POST = array_merge($_POST, $jsonData);
                    $_REQUEST = array_merge($_REQUEST, $jsonData);
                }
            }
        }

        $httpMethod = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];

        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        if ($uri === '/index.php') {
            $uri = '/';
        }

        $dispatcher = $this->get('router');
        $routeManager = new RouteManager($dispatcher, $this);

        try {
            $response = $routeManager->handleFpmRequest($httpMethod, $uri, $this, $this->requestStartTime);

            if (is_array($response) || is_object($response)) {
                header('Content-Type: application/json');
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                echo $response;
            }

        } catch (\Exception $e) {
            $logger = $this->get('logger');
            
            // 使用配置中定义的异常处理器
            if ($exceptionHandlerClass = config('exception.handler')) {
                $exceptionHandler = new $exceptionHandlerClass($logger, config('exception'));
            } else {
                // 如果没有配置异常处理器，使用默认的PHP异常处理
                throw $e;
            }
            $response = $exceptionHandler->handle($e);

            if (is_array($response) || is_object($response)) {
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo $response;
            }
        }
    }

    /**
     * 运行CLI模式
     * Run CLI mode
     *
     * @return void
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
                        echo "Server status:
";
                        echo "  Running status: " . ($status['running'] ? 'Running' : 'Not running') . "
";
                        echo "  Listening address: {$status['host']}:{$status['port']}
";
                        echo "  Worker process count: {$status['worker_num']}
";
                        echo "  PID file: {$status['pid_file']}
";
                        break;
                    default:
                        echo "Unknown server command: {$serverAction}
";
                        exit(1);
                }

                exit(0);
            }

            echo "Error: Unknown command '{$command}'
";
            echo "Please use 'php cli.php server start' to start the server, or use 'php shell.php' to execute other commands
";
            exit(1);

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * 运行Shell模式
     * Run Shell mode
     *
     * @return void
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
            // echo "  Positional arguments: php shell.php user/create John 25\n";
            echo "  Key-value arguments: php shell.php user/create name=John age=25\n";
            // echo "  Mixed arguments: php shell.php user/create John age=25\n";
            exit(1);
        }

        Facade::setContext(['method' => 'SHELL']);

        $dispatcher = $this->get('router');
        $routeManager = new RouteManager($dispatcher, $this);

        try {
            $routeManager->handleShellRequest($command, $args);
        } finally {
            Facade::clearContext();
        }
    }

    /**
     * 注册核心服务
     * Register core services
     *
     * @return void
     */
    protected function registerCoreServices()
    {
        $this->services['app'] = $this;
        parent::registerCoreServices();
    }
}
