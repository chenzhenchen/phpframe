<?php

namespace PHPFrame;

use PHPFrame\Request;
use PHPFrame\Runtime;

/**
 * Shell基础控制器类
 * Shell base controller class
 * 专门处理Shell模式的命令行任务
 * Specifically handles command line tasks in Shell mode
 */
abstract class BaseShell
{
    /**
     * @var Request 请求参数处理器
     * Request parameter handler
     */
    protected $request;
    
    /**
     * @var Runtime 运行模式检测器
     * Runtime mode detector
     */
    protected $runtimeMode;
    
    /**
     * 构造函数
     * Constructor
     */
    public function __construct()
    {
        $this->runtimeMode = new Runtime();
        $this->request = new Request();
    }
    
    /**
     * 设置命令行参数
     * Set command line parameters
     */
    public function setShellParams(array $params): void
    {
        $this->request->setParams($params);
    }
    
    /**
     * 解析命令行参数
     * Parse command line arguments
     */
    protected function parseArgs(array $args): array
    {
        $params = [];
        
        foreach ($args as $arg) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $params[$key] = $value;
            } else {
                $params[] = $arg;
            }
        }
        
        return $params;
    }
    
    /**
     * 输出信息到控制台
     * Output message to console
     */
    protected function output(string $message, string $type = 'info', bool $newline = true): void
    {
        if ($newline) {
            echo date('Y-m-d H:i:s').' ['.$type.'] '.$message . PHP_EOL;
        } else {
            echo date('Y-m-d H:i:s').' ['.$type.'] '.$message;
        }
    }
    
    /**
     * 获取请求参数
     * Get request parameter
     */
    protected function getParam(string $key, $default = null)
    {
        return $this->request->get($key, $default);
    }
    
    /**
     * 获取所有请求参数
     * Get all request parameters
     */
    protected function getParams(): array
    {
        return $this->request->all();
    }
    
    /**
     * 检查参数是否存在
     * Check if parameter exists
     */
    protected function hasParam(string $key): bool
    {
        return $this->request->has($key);
    }
    
    /**
     * 显示进度条
     * Show progress bar
     */
    protected function showProgress(int $current, int $total, int $width = 50): void
    {
        if ($total <= 0) {
            return;
        }
        
        $percent = ($current / $total) * 100;
        $barLength = floor(($current / $total) * $width);
        
        $bar = str_repeat('=', $barLength) . str_repeat(' ', $width - $barLength);
        
        echo sprintf("\\r[%s] %d/%d (%.1f%%)", $bar, $current, $total, $percent);
        
        if ($current >= $total) {
            echo PHP_EOL;
        }
    }
    
    /**
     * 执行耗时任务并显示执行时间
     * Execute time-consuming task and display execution time
     */
    protected function executeWithTimer(callable $task, string $taskName = '任务'): mixed
    {
        $startTime = microtime(true);
        $this->output("开始执行: {$taskName}");
        
        try {
            $result = $task();
            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);
            
            $this->output("{$taskName}执行完成，耗时: {$executionTime}秒", 'success');
            
            return $result;
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);
            
            $this->output("{$taskName}执行失败，耗时: {$executionTime}秒，错误: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * 批量处理数据
     * Batch process data
     */
    protected function batchProcess(array $data, callable $processor, int $chunkSize = 100, int $delay = 0): int
    {
        $total = count($data);
        $processed = 0;
        
        $this->output("开始批量处理，总数: {$total}，分块大小: {$chunkSize}");
        
        $chunks = array_chunk($data, $chunkSize);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            $this->output("处理第 " . ($chunkIndex + 1) . " 块数据，大小: " . count($chunk));
            
            foreach ($chunk as $item) {
                try {
                    $processor($item);
                    $processed++;
                    
                    // 显示进度
                    // Show progress
                    if ($processed % 10 === 0) {
                        $this->showProgress($processed, $total);
                    }
                    
                    // 延迟处理（避免过高负载）
                    // Delay processing (avoid high load)
                    if ($delay > 0) {
                        sleep($delay);
                    }
                    
                } catch (\Exception $e) {
                    $this->output("处理失败: " . $e->getMessage(), 'warning');
                }
            }
            
            // 强制垃圾回收（处理大数据集时重要）
            // Force garbage collection (important for large datasets)
            gc_collect_cycles();
        }
        
        $this->output("批量处理完成，成功处理: {$processed}/{$total}", 'success');
        
        return $processed;
    }
    
    /**
     * 确认操作
     * Confirm operation
     */
    protected function confirm(string $message): bool
    {
        $this->output($message . " (y/n): ", 'warning', false);
        
        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);
        
        return strtolower($input) === 'y' || strtolower($input) === 'yes';
    }
    
    /**
     * 获取用户输入
     * Get user input
     */
    protected function ask(string $prompt, $default = null)
    {
        $this->output($prompt . ($default ? " [{$default}]: " : ": "), 'info', false);
        
        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);
        
        return empty($input) ? $default : $input;
    }
    
    /**
     * 记录日志
     * Log message
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // 输出到控制台
        // Output to console
        $this->output($logMessage, $level);
        
        // 写入日志文件
        // Write to log file
        $logFile = runtime_path('logs/shell.log');
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 资源清理
     * Resource cleanup
     */
    public function __destruct()
    {
        // 强制垃圾回收
        // Force garbage collection
        gc_collect_cycles();
    }
}