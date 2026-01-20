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
        $this->channel = $config['channel'] ?? 'phpframe';

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

    private function addManualLog(string $level, string $message, array $context = []): void
    {
        $this->manualLogs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'time' => date('Y-m-d H:i:s'),
        ];
    }

    protected function buildLogLine(string $time, string $level, string $clientIp, string $serverIp,
                                    string $elapsedTime, int $statusCode, string $method, string $uri, string $requestData,
                                    string $userAgent, string $content): string
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

    protected function doWriteLog(string $logLine): void
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
        $this->writeLog(
            date('Y-m-d H:i:s'),
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

    public function writeLog(string $time, string $level, string $clientIp, string $serverIp,
                             string $elapsedTime, int $statusCode, string $method, string $uri, string $requestData,
                             string $userAgent, string $content): void
    {
        $logLine = [];

        if (!empty($this->manualLogs)) {
            foreach ($this->manualLogs as $log) {
                $logContent = $log['message'] . json_encode($log['context'], JSON_UNESCAPED_UNICODE);
                $logLine[] = $this->buildLogLine($log['time'], $log['level'], $clientIp, $serverIp, 0, 0, "", $uri, "", "", $logContent);
            }
            $this->manualLogs = [];
        }

        $logLine[] = $this->buildLogLine(
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
            $content
        );

        $this->doWriteLog(implode("\n", $logLine));
    }
}
