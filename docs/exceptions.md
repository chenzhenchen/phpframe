# 异常处理

PHPFrame 提供统一的异常处理机制，内置 `ExceptionHandler` 作为默认处理器，支持自定义覆盖。

## 内置异常处理器

框架内置 `PHPFrame\Exceptions\ExceptionHandler`，在未配置自定义处理器时自动使用：

- 自动记录异常日志
- 按运行模式（FPM/CLI/Shell）渲染不同格式的响应
- 调试模式下输出详细错误信息
- 生产模式下隐藏敏感信息

## 自定义异常处理器

在 `config/exception.php` 中配置：

```php
return [
    'handler' => App\Library\ExceptionHandler::class,
];
```

自定义处理器需要实现 `handle(\Throwable $exception, string $mode)` 方法：

```php
namespace App\Library;

class ExceptionHandler
{
    protected $logger;

    public function __construct($logger = null, array $config = [])
    {
        $this->logger = $logger;
    }

    public function handle(\Throwable $exception, string $mode = 'fpm')
    {
        // 记录日志
        if ($this->logger) {
            $this->logger->error($exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }

        // 按模式返回不同格式
        switch ($mode) {
            case 'fpm':
                http_response_code(500);
                return ['error' => 'Internal Server Error'];

            case 'cli':
                return new \React\Http\Message\Response(
                    500,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Internal Server Error'])
                );

            case 'shell':
                return "Error: " . $exception->getMessage();
        }
    }
}
```

## HTTP 异常

使用 `HttpException` 抛出带 HTTP 状态码的异常：

```php
use PHPFrame\Exceptions\HttpException;

// 抛出 404
throw new HttpException(404, 'Resource not found');

// 抛出 403
throw new HttpException(403, 'Access denied');

// 抛出 401
throw new HttpException(401, 'Unauthorized');
```

内置 ExceptionHandler 会自动识别 `HttpException` 并使用其状态码。

## 异常处理流程

```
异常抛出
  → set_exception_handler 回调
  → 获取 ExceptionHandler（自定义或内置）
  → ExceptionHandler::handle($exception, $mode)
    → report()：记录日志
    → render()：渲染响应
      → FPM：设置 HTTP 状态码 + 输出 JSON
      → CLI：返回 ReactResponse
      → Shell：输出到控制台
```

## 调试模式

当 `APP_DEBUG=true` 时，内置处理器会输出详细错误信息：

```json
{
    "error": "RuntimeException",
    "message": "Database connection failed",
    "file": "/app/Controllers/UserController.php",
    "line": 42,
    "trace": ["..."],
    "status_code": 500
}
```

当 `APP_DEBUG=false` 时，仅输出：

```json
{
    "error": "Internal Server Error",
    "status_code": 500
}
```

## ExceptionHandler API

| 方法 | 说明 |
|------|------|
| `handle($exception, $mode)` | 处理异常（入口方法） |
| `report($exception, $mode)` | 记录异常日志 |
| `render($exception, $mode)` | 渲染异常响应 |

## HttpException API

| 方法 | 说明 |
|------|------|
| `getStatusCode()` | 获取 HTTP 状态码 |

## Shell 模式日志

`BaseShell` 的 `log()` 方法默认通过框架 Logger 统一记录日志，保持日志格式和轮转策略一致。当 Logger 不可用时（如容器未初始化），会降级到文件写入：

```php
// 在 Shell 控制器中使用
protected function myAction()
{
    $this->log('Task started', 'info');
    $this->log('Something went wrong', 'error');
}
```
