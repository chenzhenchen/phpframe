# 日志系统

PHPFrame 使用 Monolog 作为日志引擎，提供两种日志通道：

1. **手动日志**：`Log::info()` / `Log::error()` 等，标准格式，立即写入
2. **请求日志**：`Logger::writeLog()`，管道分隔格式，由 RouteManager 自动调用

## 配置

参考 `config/log.php`：

```php
return [
    'driver' => 'file',
    'path' => runtime_path('logs'),
    'level' => 'info',
    'format' => 'Y-m-d',     // 日志文件日期格式
    'filename' => 'phpframe', // 日志文件名前缀
];
```

日志文件路径：`runtime/logs/phpframe_2026-06-13.log`

## 日志文件自动轮转

日志文件按日期自动轮转。当日期变化时（如从 6 月 13 日到 6 月 14 日），Logger 会自动关闭旧的 StreamHandler 并重建新的 Handler 指向新日期的文件，后续日志写入新日期的文件，无需重启服务：

```
runtime/logs/phpframe_2026-06-13.log  ← 6 月 13 日的日志
runtime/logs/phpframe_2026-06-14.log  ← 6 月 14 日的日志（自动切换）
```

> 这对 CLI 常驻内存模式尤为重要，服务可能连续运行数天，需要确保日志文件按日期正确切换。
>
> 轮转机制采用 Handler 重建而非反射修改私有属性，确保与 Monolog 各版本兼容。

## 手动日志

### 门面方式（推荐）

```php
use PHPFrame\Facades\Log;

Log::info('User logged in', ['user_id' => 123]);
Log::error('Database connection failed', ['host' => '127.0.0.1']);
Log::warning('Rate limit approaching', ['ip' => '192.168.1.1']);
Log::debug('Query executed', ['sql' => $sql, 'time' => $time]);
Log::notice('Cache miss', ['key' => 'users:active']);
Log::log('info', 'Custom level log', ['context' => 'data']);
```

### 容器方式

```php
$logger = app('logger');
$logger->info('Message', ['context' => 'data']);
$logger->error('Error message');
```

### 日志格式

手动日志输出格式：

```
[2026-06-13 10:30:00] phpframe.INFO: User logged in {"user_id":123}
```

## 请求日志

请求日志由 `RouteManager` 自动调用 `Logger::writeLog()` 记录，格式为管道分隔：

```
2026-06-13 10:30:00|info|192.168.1.1|127.0.0.1|15.23|200|GET|/api/users|{"page":1}|||
```

字段顺序：`时间|级别|客户端IP|服务器IP|耗时(ms)|状态码|方法|URI|请求数据|User-Agent|错误信息`

请求日志在 FPM / CLI / Shell 三种模式下均自动记录。

## 获取 Monolog 实例

```php
$logger = app('logger');

// 手动日志 Monolog
$monolog = $logger->getMonolog();

// 请求日志 Monolog
$requestMonolog = $logger->getRequestMonolog();

// 通过 Log 门面
$monolog = Log::getLogger();
```

## 日志级别

| 级别 | 方法 | 说明 |
|------|------|------|
| `debug` | `Log::debug()` | 调试信息 |
| `info` | `Log::info()` | 一般信息 |
| `notice` | `Log::notice()` | 正常但值得注意 |
| `warning` | `Log::warning()` | 警告 |
| `error` | `Log::error()` | 错误 |

> 注意：Logger 类仅实现了以上 5 个级别方法。虽然 Facade 的上下文注入机制支持 `alert`/`critical`/`emergency`，但 Logger 类本身未实现这些方法，调用会报错。如需使用更多级别，可通过 `$logger->getMonolog()->alert(...)` 直接调用 Monolog。

## Logger API

| 方法 | 说明 |
|------|------|
| `info($message, $context)` | 记录 INFO 日志 |
| `error($message, $context)` | 记录 ERROR 日志 |
| `warning($message, $context)` | 记录 WARNING 日志 |
| `debug($message, $context)` | 记录 DEBUG 日志 |
| `notice($message, $context)` | 记录 NOTICE 日志 |
| `log($level, $message, $context)` | 记录指定级别日志 |
| `writeLog(...)` | 写入请求日志（管道格式） |
| `getMonolog()` | 获取手动日志 Monolog 实例 |
| `getRequestMonolog()` | 获取请求日志 Monolog 实例 |
| `getLogFilePath()` | 获取当前日志文件路径 |
| `getInstance($config)` | 获取 Logger 单例 |
| `setRequestData($data)` | 设置请求数据 |
| `setRequestStartTime($time)` | 设置请求开始时间 |
| `getRequestData()` | 获取请求数据 |
