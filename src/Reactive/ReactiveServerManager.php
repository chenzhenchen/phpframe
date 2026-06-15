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
 * 支持多Worker进程、启动、停止、重载和重启操作
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
     * Worker 进程 PID 列表
     */
    protected array $workerPids = [];

    /**
     * 构造函数
     */
    public function __construct($host = '0.0.0.0', $port = 8000, $workerNum = 4, $deamon = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->workerNum = max(1, (int)$workerNum);
        $this->deamon = $deamon;
        $this->pidFile = runtime_path('logs/reactive_server.pid');

        echo "ReactiveServerManager initialized with host: {$this->host}, port: {$this->port}, workers: {$this->workerNum}\n";
    }

    /**
     * 启动服务器
     */
    public function start()
    {
        if ($this->isRunning()) {
            echo "检测到服务器已在运行，正在停止旧进程...\n";
            $this->forceStop();
            sleep(2);
        }

        echo "正在启动PHPFrame(CLI MODE)...\n";
        echo "监听地址: {$this->host}:{$this->port}\n";
        echo "Worker进程数: {$this->workerNum}\n";

        if ($this->deamon) {
            $command = sprintf(
                'nohup php %s/cli.php server run --host=%s --port=%d --worker=%d > %s 2>&1 & echo $!',
                ROOT_PATH,
                $this->host,
                $this->port,
                $this->workerNum,
                runtime_path('logs/server_' . date('Ymd_His') . '.log')
            );

            $pid = shell_exec($command);
            $pid = trim($pid);

            if (empty($pid)) {
                die("启动服务器失败\n");
            }

            file_put_contents($this->pidFile, $pid);
            echo "服务器已启动为守护进程，主进程PID: {$pid}\n";

            sleep(2);

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
     * 运行服务器（主进程）
     * 当 workerNum > 1 时，fork 多个 Worker 进程
     */
    public function run()
    {
        echo "服务器主进程启动...\n";

        $this->masterPid = getmypid();

        // 保存主进程PID
        $this->savePidInfo();

        // 注册主进程信号处理
        $this->registerMasterSignalHandlers();

        if ($this->workerNum > 1 && function_exists('pcntl_fork')) {
            echo "启动 {$this->workerNum} 个Worker进程...\n";
            $this->forkWorkers();
        } else {
            if ($this->workerNum > 1 && !function_exists('pcntl_fork')) {
                echo "警告: pcntl 扩展未安装，无法多进程运行，使用单进程模式\n";
            }
            echo "以单进程模式运行...\n";
            $this->runSingleWorker();
        }
    }

    /**
     * Fork 多个 Worker 进程
     */
    protected function forkWorkers(): void
    {
        for ($i = 0; $i < $this->workerNum; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                die("fork Worker 进程失败\n");
            }

            if ($pid === 0) {
                // 子进程：运行 Worker
                $this->runSingleWorker($i);
                exit(0);
            }

            // 父进程：记录 Worker PID
            $this->workerPids[] = $pid;
            echo "Worker #{$i} 启动，PID: {$pid}\n";
        }

        // 父进程：监控 Worker 进程
        $this->monitorWorkers();
    }

    /**
     * 监控 Worker 进程，自动重启退出的 Worker
     */
    protected function monitorWorkers(): void
    {
        while (true) {
            $status = null;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0) {
                $index = array_search($pid, $this->workerPids);
                if ($index !== false) {
                    echo "Worker #{$index} (PID: {$pid}) 已退出，正在重启...\n";
                    unset($this->workerPids[$index]);
                    $this->workerPids = array_values($this->workerPids);

                    // 重启 Worker
                    $newPid = pcntl_fork();
                    if ($newPid === -1) {
                        echo "重启 Worker 失败\n";
                    } elseif ($newPid === 0) {
                        $this->runSingleWorker($index);
                        exit(0);
                    } else {
                        $this->workerPids[] = $newPid;
                        echo "Worker #{$index} 已重启，新PID: {$newPid}\n";
                        // 更新 PID 文件
                        $this->savePidInfo();
                    }
                }
            }

            usleep(100000); // 100ms
        }
    }

    /**
     * 运行单个 Worker（ReactPHP 事件循环）
     */
    protected function runSingleWorker(int $workerId = 0): void
    {
        $this->loop = Loop::get();

        $requestHandler = $this->createRequestHandler();
        $multipartMiddleware = new MultipartFormDataMiddleware();

        $this->httpServer = new HttpServer(
            $multipartMiddleware,
            function (ServerRequestInterface $request) use ($requestHandler) {
                return $requestHandler->handle($request);
            }
        );

        // 多 Worker 模式使用 SO_REUSEPORT，允许各进程绑定同一端口，内核负载均衡
        $context = [];
        if ($this->workerNum > 1) {
            $context = [
                'tcp' => [
                    'so_reuseport' => true,
                    'so_reuseaddr' => true,
                ],
            ];
        }

        $socket = new SocketServer("{$this->host}:{$this->port}", $context);
        $this->httpServer->listen($socket);

        echo "Worker #{$workerId} (PID: " . getmypid() . ") 已启动: http://{$this->host}:{$this->port}\n";

        // Worker 进程信号处理
        $this->registerWorkerSignalHandlers();

        $this->loop->run();
    }

    /**
     * 创建请求处理适配器
     * 复用容器中的 RouteManager 实例，保留中间件注册
     */
    protected function createRequestHandler()
    {
        $dispatcher = app('router');
        $container = app();

        // 尝试获取已有的 RouteManager 实例，保留中间件注册
        $routeManager = null;
        if ($container->has('route_manager')) {
            $routeManager = $container->get('route_manager');
        }

        $requestHandler = new ReactiveRequestHandler($dispatcher, $container, $routeManager);

        // 将路由注册阶段暂存的中间件应用到 RouteManager
        \PHPFrame\Facades\Route::applyPendingMiddlewares($requestHandler->getRouteManager());

        return $requestHandler;
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

        $pid = $this->getMasterPid();

        // 发送SIGTERM信号给主进程
        posix_kill($pid, SIGTERM);

        // 同时停止所有 Worker
        $this->stopWorkers();

        $timeout = 10;
        $startTime = time();

        while ($this->isRunning()) {
            if (time() - $startTime > $timeout) {
                echo "服务器停止超时，强制终止...\n";
                $this->forceStop();
                break;
            }
            usleep(100000);
        }

        $this->cleanupPidFile();
        echo "服务器已停止\n";
    }

    /**
     * 停止所有 Worker 进程
     * 从 PID 文件读取 Worker PID 列表，确保跨进程也能停止
     */
    protected function stopWorkers(): void
    {
        // 优先使用内存中的 PID 列表
        $pids = $this->workerPids;

        // 如果内存为空，从 PID 文件读取
        if (empty($pids) && file_exists($this->pidFile)) {
            $data = json_decode(file_get_contents($this->pidFile), true);
            $pids = $data['worker_pids'] ?? [];
        }

        foreach ($pids as $pid) {
            @posix_kill($pid, SIGTERM);
        }
    }

    /**
     * 强制停止服务器
     */
    public function forceStop()
    {
        if (!$this->isRunning()) {
            return;
        }

        $pid = $this->getMasterPid();

        // 从 PID 文件读取 Worker PID
        $workerPids = $this->workerPids;
        if (empty($workerPids) && file_exists($this->pidFile)) {
            $data = json_decode(file_get_contents($this->pidFile), true);
            $workerPids = $data['worker_pids'] ?? [];
        }

        // 先杀 Worker
        foreach ($workerPids as $workerPid) {
            @posix_kill($workerPid, SIGKILL);
        }

        // 再杀主进程
        posix_kill($pid, SIGKILL);

        $timeout = 5;
        $startTime = time();

        while ($this->isRunning()) {
            if (time() - $startTime > $timeout) {
                echo "强制停止超时，尝试杀死进程树...\n";
                shell_exec("pkill -P {$pid}");
                break;
            }
            usleep(100000);
        }

        $this->cleanupPidFile();
    }

    /**
     * 重载服务器配置（优雅重载 Worker）
     */
    public function reload()
    {
        if (!$this->isRunning()) {
            echo "服务器未运行\n";
            return;
        }

        // 向所有 Worker 发送 SIGUSR1 信号（优雅重载）
        foreach ($this->workerPids as $pid) {
            @posix_kill($pid, SIGUSR1);
        }

        echo "已向所有Worker发送重载信号\n";
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
     */
    protected function isRunning(): bool
    {
        if (!file_exists($this->pidFile)) {
            return false;
        }

        $pid = $this->getMasterPid();

        if (!$pid) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    /**
     * 获取主进程PID
     */
    protected function getMasterPid(): ?int
    {
        if (!file_exists($this->pidFile)) {
            return null;
        }

        $content = file_get_contents($this->pidFile);
        $data = json_decode($content, true);

        return $data['master_pid'] ?? (int)trim($content) ?: null;
    }

    /**
     * 保存 PID 信息到文件
     */
    protected function savePidInfo(): void
    {
        $data = [
            'master_pid' => $this->masterPid,
            'worker_pids' => $this->workerPids,
            'host' => $this->host,
            'port' => $this->port,
            'worker_num' => $this->workerNum,
            'started_at' => date('Y-m-d H:i:s'),
        ];

        file_put_contents($this->pidFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * 清理 PID 文件
     */
    protected function cleanupPidFile(): void
    {
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
    }

    /**
     * 注册主进程信号处理器
     */
    protected function registerMasterSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, function () {
            echo "主进程收到SIGTERM信号，正在停止所有Worker...\n";
            $this->stopWorkers();
            $this->cleanupPidFile();
            exit(0);
        });

        pcntl_signal(SIGINT, function () {
            echo "主进程收到SIGINT信号，正在停止所有Worker...\n";
            $this->stopWorkers();
            $this->cleanupPidFile();
            exit(0);
        });

        pcntl_async_signals(true);
    }

    /**
     * 注册 Worker 进程信号处理器
     */
    protected function registerWorkerSignalHandlers(): void
    {
        $this->loop->addSignal(SIGTERM, function () {
            echo "Worker (PID: " . getmypid() . ") 收到SIGTERM信号，正在关闭...\n";
            $this->loop->stop();
        });

        $this->loop->addSignal(SIGINT, function () {
            echo "Worker (PID: " . getmypid() . ") 收到SIGINT信号，正在关闭...\n";
            $this->loop->stop();
        });

        $this->loop->addSignal(SIGUSR1, function () {
            echo "Worker (PID: " . getmypid() . ") 收到重载信号\n";
            // 可在此处重新加载配置、重连数据库等
        });
    }

    /**
     * 获取服务器状态
     */
    public function getStatus(): array
    {
        $running = $this->isRunning();
        $pidData = null;

        if ($running && file_exists($this->pidFile)) {
            $pidData = json_decode(file_get_contents($this->pidFile), true);
        }

        return [
            'running' => $running,
            'host' => $this->host,
            'port' => $this->port,
            'worker_num' => $this->workerNum,
            'master_pid' => $pidData['master_pid'] ?? null,
            'worker_pids' => $pidData['worker_pids'] ?? [],
            'pid_file' => $this->pidFile,
            'started_at' => $pidData['started_at'] ?? null,
        ];
    }
}
