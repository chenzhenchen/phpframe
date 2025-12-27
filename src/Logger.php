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
    public const CRITICAL = 'critical';
    public const ALERT = 'alert';
    public const EMERGENCY = 'emergency';

    protected static $instance = null;

    protected $logPath;
    protected $filename;
    protected $dateFormat;
    protected $channel;
    protected $monolog;
    protected $requestStartTime;
    protected $requestData = [];
    protected $manualLogs = [];
    protected $cachedLogPath = null;
    protected $cachedDate = null;

    public function __construct(array $config = [])
    {
        $this->logPath = $config['path'] ?? runtime_path('logs');
        $this->filename = $config['filename'] ?? 'phpframe';
        $this->dateFormat = $config['format'] ?? 'Y-m-d';
        $this->channel = 'phpframe';

        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }

        $this->initMonolog();
    }

    public static function getInstance(array $config = [])
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    protected function initMonolog()
    {
        $this->monolog = new MonologLogger($this->channel);

        $logFile = $this->getLogFilePath();

        $handler = new StreamHandler($logFile, MonologLogger::DEBUG);
        $formatter = new LineFormatter(null, $this->dateFormat, false, true);
        $handler->setFormatter($formatter);

        $this->monolog->pushHandler($handler);
    }

    public function getLogFilePath(): string
    {
        $currentDate = date($this->dateFormat);
        
        if ($this->cachedLogPath !== null && $this->cachedDate === $currentDate) {
            return $this->cachedLogPath;
        }
        
        $this->cachedDate = $currentDate;
        $this->cachedLogPath = $this->logPath . DIRECTORY_SEPARATOR . $this->filename . '_' . $currentDate . '.log';
        
        return $this->cachedLogPath;
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

    public function addManualLog(string $level, string $message, array $context = []): void
    {
        $this->manualLogs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'time' => microtime(true),
        ];
    }

    public function getManualLogs(): array
    {
        return $this->manualLogs;
    }

    public function clearManualLogs(): void
    {
        $this->manualLogs = [];
    }

    public function info(string $message, array $context = []): void
    {
        $this->addManualLog('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->addManualLog('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->addManualLog('warning', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->addManualLog('debug', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->addManualLog('notice', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->addManualLog('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->addManualLog('critical', $message, $context);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->addManualLog('emergency', $message, $context);
    }

    public function recordAutoLog(string $clientIp, string $serverIp, string $uri, string $userAgent, int $statusCode = 200): void
    {
        $requestData = $this->prepareRequestData();
        $requestDataStr = !empty($requestData) ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : '';

        $elapsedTime = 0;
        if ($this->requestStartTime) {
            $elapsedTime = round((microtime(true) - $this->requestStartTime) * 1000, 2);
        }

        $logLine = $this->buildLogLine('info', $clientIp, $serverIp, $elapsedTime, $statusCode, $this->requestData['method'] ?? 'UNKNOWN', $uri, $requestDataStr, $userAgent, "");

        $this->writeLog($logLine);
    }

    public function recordManualLogs(string $clientIp, string $serverIp, string $uri, string $userAgent): void
    {
        foreach ($this->manualLogs as $log) {
            $requestData = $this->prepareRequestData();
            $requestDataStr = !empty($requestData) ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : '';

            $contextStr = !empty($log['context']) ? ' ' . json_encode($log['context'], JSON_UNESCAPED_UNICODE) : '';
            $logContent = $log['message'] . $contextStr;

            $logLine = $this->buildLogLine($log['level'], $clientIp, $serverIp, 0, 0, $this->requestData['method'] ?? 'UNKNOWN', $uri, $requestDataStr, $userAgent, $logContent);

            $this->writeLog($logLine);
        }
    }

    public function recordErrorLog(string $clientIp, string $serverIp, string $uri, string $userAgent, string $errorMessage, string $stackTrace = ''): void
    {
        $requestData = $this->prepareRequestData();
        $requestDataStr = !empty($requestData) ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : '';

        $content = [
            'type' => 'error',
            'message' => $errorMessage,
            'stack_trace' => $stackTrace,
        ];

        $logContent = json_encode($content, JSON_UNESCAPED_UNICODE);

        $logLine = $this->buildLogLine('error', $clientIp, $serverIp,  0, 0, $this->requestData['method'] ?? 'UNKNOWN', $uri, $requestDataStr, $userAgent, $logContent);

        $this->writeLog($logLine);
    }

    protected function buildLogLine(string $level, string $clientIp, string $serverIp, 
    string $elapsedTime, int $statusCode, string $method, string $uri, string $requestData, 
    string $userAgent, string $content): string
    {
        $time = date('Y-m-d H:i:s');
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

    protected function writeLog(string $logLine): void
    {
        @file_put_contents($this->getLogFilePath(), $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

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

    public function getMonolog(): MonologLogger
    {
        return $this->monolog;
    }

    public function log($level, $message, array $context = []): void
    {
        $clientIp = $context['client_ip'] ?? '';
        $serverIp = $context['server_ip'] ?? '';
        $uri = $context['uri'] ?? '';
        $userAgent = $context['user_agent'] ?? '';
        $method = strtoupper($context['method'] ?? $this->requestData['method'] ?? Runtime::detect());
        
        $requestData = $this->prepareRequestData();
        $requestDataStr = !empty($requestData) ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : '';
        
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logContent = $message . $contextStr;
        
        $this->writeLogLine(
            $level,
            $clientIp,
            $serverIp,
            0,
            0,
            $method,
            $uri,
            $requestDataStr,
            $userAgent,
            $logContent
        );
    }

    public function writeLogLine(string $level, string $clientIp, string $serverIp, 
    string $elapsedTime, int $statusCode, string $method, string $uri, string $requestData, 
    string $userAgent, string $content): void
    {
        $logLine = $this->buildLogLine(
            $level,
            $clientIp,
            $serverIp,
            $elapsedTime,
            $statusCode,
            $method,
            $uri,
            $requestData,
            $userAgent,
            $content
        );

        $this->writeLog($logLine);
    }
}
