<?php

namespace PHPFrame\Reactive;

use React\Http\HttpServer;
use React\Socket\SocketServer;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use FastRoute\Dispatcher;

/**
 * 基于ReactPHP的常驻内存服务器管理器
 * 支持启动、停止、重载和重启操作
 */
class ReactiveServerManager
{
    protected $host;
    protected $port;
    protected $workerNum;

    protected $deamon;
    protected $pidFile;
    protected $masterPid;
    protected $httpServer;
    protected $loop;
    protected $container;
    
    /**
     * 构造函数
     *
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param int $workerNum worker进程数量
     */
    public function __construct($host = '0.0.0.0', $port = 8000, $workerNum = 4,$deamon = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->workerNum = $workerNum;
        $this->deamon = $deamon;
        $this->pidFile = runtime_path('logs/reactive_server.pid');
        
        echo "ReactiveServerManager initialized with host: {$this->host}, port: {$this->port}\n";
    }
    
    /**
     * 启动服务器
     */
    public function start()
    {
        // 检查是否已经在运行，如果是则强制停止
        if ($this->isRunning()) {
            echo "检测到服务器已在运行，正在停止旧进程...\n";
            $this->forceStop();
            sleep(2); // 等待进程完全停止
        }
        
        echo "正在启动PHPFrame(CLI MODE)...\n";
        echo "监听地址: {$this->host}:{$this->port}\n";
        echo "Worker进程数: {$this->workerNum}\n";
        if ($this->deamon) {
            // 使用nohup启动后台进程
            $command = sprintf(
                'nohup php %s/cli.php server run --host=%s --port=%d --worker=%d > %s 2>&1 & echo $!',
                ROOT_PATH,
                $this->host,
                $this->port,
                $this->workerNum,
                runtime_path('logs/server_'.date('Ymd_His').'.log')
            );

            $pid = shell_exec($command);
            $pid = trim($pid);

            if (empty($pid)) {
                die("启动服务器失败\n");
            }

            // 保存PID到文件
            file_put_contents($this->pidFile, $pid);

            echo "服务器已启动为守护进程，主进程PID: {$pid}\n";
            echo "日志文件: " . runtime_path('logs/server.log') . "\n";

            // 等待服务器启动
            sleep(2);

            // 检查服务器是否成功启动
            if ($this->isRunning()) {
                echo "服务器启动成功！\n";
            } else {
                echo "警告：服务器可能没有成功启动，请检查日志文件\n";
            }
        } else {
            $this->run();
        }
    }
    
    /**
     * 运行服务器（内部方法，由start方法调用）
     */
    public function run()
    {
        echo "服务器进程启动...\n";
        
        // 获取事件循环
        $this->loop = Loop::get();
        
        // 创建请求处理适配器
        $requestHandler = $this->createRequestHandler();
        
        // 创建multipart/form-data解析中间件
        $multipartMiddleware = new MultipartFormDataMiddleware();
        
        // 创建HTTP服务器，添加multipart/form-data解析中间件
        $this->httpServer = new HttpServer(
            $multipartMiddleware,
            function (ServerRequestInterface $request) use ($requestHandler) {
                return $requestHandler->handle($request);
            }
        );
        
        // 创建Socket服务器
        $socket = new SocketServer("{$this->host}:{$this->port}");
        $this->httpServer->listen($socket);
        
        // 保存主进程PID
        $this->masterPid = getmypid();
        file_put_contents($this->pidFile, $this->masterPid);
        
        echo "服务器已启动: http://{$this->host}:{$this->port}\n";
        echo "主进程PID: {$this->masterPid}\n";
        
        // 注册信号处理器
        $this->registerSignalHandlers();
        
        // 启动事件循环
        $this->loop->run();
    }
    
    /**
     * 创建请求处理适配器
     *
     * @return ReactiveRequestHandler
     */
    protected function createRequestHandler()
    {
        // 获取路由调度器和容器
        $dispatcher = app('router');
        $container = app();
        
        // 创建请求处理适配器
        return new ReactiveRequestHandler($dispatcher, $container);
    }
    
    /**
     * 停止服务器
     */
    public function stop()
    {
        if (!$this->isRunning()) {
            echo "服务器未运行\n";
            return;
        }
        
        $pid = file_get_contents($this->pidFile);
        
        // 发送SIGTERM信号
        posix_kill($pid, SIGTERM);
        
        // 等待进程退出
        $timeout = 10; // 10秒超时
        $startTime = time();
        
        while ($this->isRunning()) {
            if (time() - $startTime > $timeout) {
                echo "服务器停止超时，强制终止...\n";
                $this->forceStop();
                break;
            }
            usleep(100000); // 100ms
        }
        
        // 清理PID文件
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
        
        echo "服务器已停止\n";
    }
    
    /**
     * 强制停止服务器
     */
    public function forceStop()
    {
        if (!$this->isRunning()) {
            return;
        }
        
        $pid = file_get_contents($this->pidFile);
        
        // 发送SIGKILL信号
        posix_kill($pid, SIGKILL);
        
        // 等待进程退出
        $timeout = 5; // 5秒超时
        $startTime = time();
        
        while ($this->isRunning()) {
            if (time() - $startTime > $timeout) {
                echo "强制停止超时，尝试杀死进程树...\n";
                // 尝试杀死整个进程树
                shell_exec("pkill -P {$pid}");
                break;
            }
            usleep(100000); // 100ms
        }
        
        // 清理PID文件
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
    }
    
    /**
     * 重载服务器配置
     */
    public function reload()
    {
        if (!$this->isRunning()) {
            echo "服务器未运行\n";
            return;
        }
        
        $pid = file_get_contents($this->pidFile);
        
        // 发送SIGHUP信号
        posix_kill($pid, SIGHUP);
        
        echo "服务器配置已重载\n";
    }
    
    /**
     * 重启服务器
     */
    public function restart()
    {
        $this->stop();
        sleep(2);
        $this->start();
    }
    
    /**
     * 检查服务器是否在运行
     *
     * @return bool
     */
    protected function isRunning(): bool
    {
        if (!file_exists($this->pidFile)) {
            return false;
        }
        
        $pid = file_get_contents($this->pidFile);
        
        if (!$pid) {
            return false;
        }
        
        return posix_kill($pid, 0);
    }
    
    /**
     * 注册信号处理器
     */
    protected function registerSignalHandlers()
    {
        // 注册SIGTERM信号处理器（优雅关闭）
        $this->loop->addSignal(SIGTERM, function () {
            echo "收到SIGTERM信号，正在优雅关闭服务器...\n";
            $this->loop->stop();
        });
        
        // 注册SIGINT信号处理器（Ctrl+C）
        $this->loop->addSignal(SIGINT, function () {
            echo "收到SIGINT信号，正在关闭服务器...\n";
            $this->loop->stop();
        });
        
        // 注册SIGHUP信号处理器（重载配置）
        $this->loop->addSignal(SIGHUP, function () {
            echo "收到SIGHUP信号，正在重载服务器配置...\n";
            // 这里可以重新加载配置文件等操作
        });
    }
    
    /**
     * 获取服务器状态
     *
     * @return array
     */
    public function getStatus(): array
    {
        return [
            'running' => $this->isRunning(),
            'host' => $this->host,
            'port' => $this->port,
            'worker_num' => $this->workerNum,
            'pid_file' => $this->pidFile,
        ];
    }
}