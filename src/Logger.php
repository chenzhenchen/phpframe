<?php

namespace PHPFrame;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    protected static ?Logger $instance = null;

    protected string $logPath;
    protected string $filename;
    protected string $dateFormat;
    protected string $channel;

    /**
     * Monolog 实例（用于 Log::info/error 等手动日志）
     */
    protected MonologLogger $monolog;

    /**
     * 请求日志专用的 Monolog 实例（管道分隔格式，兼容原有 writeLog 格式）
     */
    protected MonologLogger $requestMonolog;

    protected ?float $requestStartTime = null;
    protected array $requestData = [];

    protected ?string $cachedLogPath = null;
    protected ?string $cachedDate = null;

    public function __construct(array $config = [])
    {
        $this->logPath = $config['path'] ?? runtime_path('logs');
        $this->filename = $config['filename'] ?? 'phpframe';
        $this->dateFormat = $config['format'] ?? 'Y-m-d';
        $this->channel = $config['channel'] ?? 'phpframe';

        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }

        $this->initMonolog();
    }

    public static function getInstance(array $config = []): static
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * 初始化 Monolog
     * - monolog: 用于 Log::info/error 等手动日志（标准格式，立即写入）
     * - requestMonolog: 用于 RouteManager 的请求日志（管道分隔格式）
     */
    protected function initMonolog(): void
    {
        $logFile = $this->getLogFilePath();

        // 手动日志 Monolog（标准 LineFormatter）
        $this->monolog = new MonologLogger($this->channel);
        $handler = new StreamHandler($logFile, MonologLogger::DEBUG);
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            false,
            true
        );
        $handler->setFormatter($formatter);
        $this->monolog->pushHandler($handler);

        // 请求日志 Monolog（管道分隔格式，兼容原有 writeLog 格式）
        $this->requestMonolog = new MonologLogger($this->channel . '.request');
        $requestHandler = new StreamHandler($logFile, MonologLogger::DEBUG);
        $requestFormatter = new LineFormatter('%message%', 'Y-m-d H:i:s', false, true);
        $requestHandler->setFormatter($requestFormatter);
        $this->requestMonolog->pushHandler($requestHandler);
    }

    public function getLogFilePath(): string
    {
        $currentDate = date($this->dateFormat);

        if ($this->cachedLogPath !== null && $this->cachedDate === $currentDate) {
            return $this->cachedLogPath;
        }

        $this->cachedDate = $currentDate;
        $this->cachedLogPath = $this->logPath . DIRECTORY_SEPARATOR . $this->filename . '_' . $currentDate . '.log';

        // 日期变化时更新 Monolog handler 的日志文件路径
        $this->rotateHandlers();

        return $this->cachedLogPath;
    }

    /**
     * 日期变化时更新 StreamHandler 的目标文件
     */
    protected function rotateHandlers(): void
    {
        if ($this->cachedLogPath === null) {
            return;
        }

        foreach ([$this->monolog, $this->requestMonolog] as $logger) {
            if ($logger === null) {
                continue;
            }
            foreach ($logger->getHandlers() as $handler) {
                if ($handler instanceof StreamHandler) {
                    // Monolog StreamHandler 的 $url 属性存储日志文件路径
                    // 使用反射更新，因为 setUrl() 方法在旧版本中可能不存在
                    try {
                        $ref = new \ReflectionProperty($handler, 'url');
                        $ref->setAccessible(true);
                        $ref->setValue($handler, $this->cachedLogPath);
                    } catch (\ReflectionException $e) {
                        // 降级：关闭旧 handler 并创建新 handler
                        $handler->close();
                    }
                }
            }
        }
    }

    public function setRequestData(array $data): void
    {
        $this->requestData = $data;
    }

    public function setRequestStartTime(float $time): void
    {
        $this->requestStartTime = $time;
    }

    public function getRequestData(): array
    {
        return $this->requestData;
    }

    // ─── 手动日志方法（Log::info / Log::error 等，立即写入） ───

    public function info(string $message, array $context = []): void
    {
        $this->monolog->info($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->monolog->error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->monolog->warning($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->monolog->debug($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->monolog->notice($message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $levelMap = [
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'notice' => MonologLogger::NOTICE,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
        ];

        $monologLevel = $levelMap[strtolower($level)] ?? MonologLogger::INFO;
        $this->monolog->log($monologLevel, $message, $context);
    }

    // ─── 请求日志方法（供 RouteManager::autoLog 调用） ───

    /**
     * 写入请求日志（管道分隔格式，兼容原有格式）
     * RouteManager 的 autoLog() 调用此方法
     */
    public function writeLog(
        string $time,
        string $level,
        string $clientIp,
        string $serverIp,
        string $elapsedTime,
        int    $statusCode,
        string $method,
        string $uri,
        string $requestData,
        string $userAgent,
        string $content
    ): void
    {
        $logLine = $this->buildLogLine(
            $time, $level, $clientIp, $serverIp,
            $elapsedTime, $statusCode, $method, $uri,
            $requestData, $userAgent, $content
        );

        $this->requestMonolog->info($logLine);
    }

    /**
     * 构建管道分隔的日志行
     */
    protected function buildLogLine(
        string $time, string $level, string $clientIp, string $serverIp,
        string $elapsedTime, int $statusCode, string $method, string $uri,
        string $requestData, string $userAgent, string $content
    ): string
    {
        return implode('|', [
            $time,
            $level,
            $clientIp,
            $serverIp,
            $elapsedTime,
            $statusCode,
            $method,
            $uri,
            $requestData,
            $userAgent,
            $content,
        ]);
    }

    /**
     * 准备请求数据（兼容旧版，供子类覆写）
     */
    protected function prepareRequestData(): array
    {
        if (empty($this->requestData)) {
            return [];
        }

        $data = [];
        $rd = $this->requestData;

        if (isset($rd['method'])) $data['method'] = $rd['method'];
        if (isset($rd['get'])) $data['GET'] = $rd['get'];
        if (isset($rd['post'])) $data['POST'] = $rd['post'];
        if (isset($rd['json'])) $data['JSON'] = $rd['json'];
        if (isset($rd['files'])) $data['FILES'] = $rd['files'];
        if (isset($rd['args'])) $data['ARGS'] = $rd['args'];

        return $data;
    }

    // ─── Monolog 访问器 ───

    public function getMonolog(): MonologLogger
    {
        return $this->monolog;
    }

    public function getRequestMonolog(): MonologLogger
    {
        return $this->requestMonolog;
    }
}
