# 中间件

PHPFrame 提供洋葱模型的中间件机制，支持全局中间件和路由级中间件。

## 中间件接口

所有中间件必须实现 `MiddlewareInterface`：

```php
namespace PHPFrame\Middleware;

interface MiddlewareInterface
{
    /**
     * @param mixed $request 请求对象（FPM 模式为 null，CLI 模式为 ServerRequestInterface）
     * @param \Closure $next 传递给下一个中间件的闭包
     * @return mixed
     */
    public function handle($request, \Closure $next);
}
```

## 创建中间件

```php
use PHPFrame\Middleware\MiddlewareInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle($request, \Closure $next)
    {
        $token = app('request')->getBearerToken();

        if (!$token || !$this->validateToken($token)) {
            return ['code' => 401, 'message' => 'Unauthorized'];
        }

        // 传递给下一个中间件
        $response = $next($request);

        // 可以在响应后做处理（洋葱模型的外层）
        return $response;
    }

    private function validateToken($token): bool
    {
        // 验证逻辑
        return true;
    }
}
```

## 注册全局中间件

全局中间件对所有路由生效：

```php
use PHPFrame\Facades\Route;

// 通过 Route Facade 注册（推荐）
Route::middleware(new AuthMiddleware());

// 或通过 RouteManager 实例
$routeManager = app('router');
$routeManager->middleware(new AuthMiddleware());

// 批量注册
$routeManager->middlewares([
    new CorsMiddleware(),
    new AuthMiddleware(),
    new RateLimitMiddleware(),
]);
```

> **延迟注册机制**：通过 `Route` 门面注册的中间件会先暂存到内部队列，在 `RouteManager` 创建后由框架自动调用 `Route::applyPendingMiddlewares()` 统一应用。这意味着你可以在路由文件中自由调用 `Route::middleware()` 等方法，无需关心 `RouteManager` 是否已初始化。

## 注册路由级中间件

路由级中间件通过别名注册，然后绑定到指定的控制器方法（handler）：

```php
use PHPFrame\Facades\Route;

// 第一步：注册中间件别名（推荐通过 Route Facade）
Route::registerMiddleware('auth', new AuthMiddleware());
Route::registerMiddleware('throttle', new ThrottleMiddleware());

// 第二步：将中间件绑定到指定 handler
Route::handlerMiddleware('App\Controllers\UserController@profile', ['auth']);
Route::handlerMiddleware('App\Controllers\PostController@create', ['auth', 'throttle']);

// 也可以通过 RouteManager 实例操作
$routeManager = app('router');
$routeManager->registerMiddleware('auth', new AuthMiddleware());
$routeManager->handlerMiddleware('App\Controllers\UserController@profile', ['auth']);

// 获取中间件
$authMiddleware = $routeManager->getRouteMiddleware('auth');
```

> 同样受延迟注册机制影响：通过 `Route` 门面调用的 `registerMiddleware()` 和 `handlerMiddleware()` 也会暂存，由框架在 `RouteManager` 创建后统一应用。

路由级中间件在请求匹配到对应 handler 时自动应用，与全局中间件按顺序合并执行：

```
全局中间件 → 路由级中间件 → 控制器
```

## 中间件管道（洋葱模型）

中间件按注册顺序执行，形成洋葱模型：

```
请求 → Middleware1 → Middleware2 → Middleware3 → 控制器
                                                      ↓
响应 ← Middleware1 ← Middleware2 ← Middleware3 ← 控制器返回
```

```
┌──────────────────────────────────────────┐
│ Middleware1 (before)                      │
│   ┌──────────────────────────────────┐   │
│   │ Middleware2 (before)              │   │
│   │   ┌──────────────────────────┐   │   │
│   │   │ Middleware3 (before)      │   │   │
│   │   │   ┌──────────────────┐   │   │   │
│   │   │   │  Controller      │   │   │   │
│   │   │   └──────────────────┘   │   │   │
│   │   │ Middleware3 (after)       │   │   │
│   │   └──────────────────────────┘   │   │
│   │ Middleware2 (after)               │   │
│   └──────────────────────────────────┘   │
│ Middleware1 (after)                       │
└──────────────────────────────────────────┘
```

## CLI 模式中间件

CLI（ReactPHP）模式下，`ReactiveRequestHandler` 会从容器获取 `RouteManager` 实例，复用 FPM 模式下注册的全局中间件和路由级中间件，确保两种模式下的中间件行为一致。

## 执行流程

当没有注册中间件时，请求直接到达控制器（零开销）：

```
请求 → 控制器 → 响应
```

当有中间件时，通过 `MiddlewarePipeline` 处理：

```
请求 → MiddlewarePipeline::process() → 中间件链 → 控制器 → 响应
```

## 常见中间件示例

### CORS 中间件

```php
class CorsMiddleware implements MiddlewareInterface
{
    public function handle($request, \Closure $next)
    {
        // FPM 模式设置 CORS 头
        if (Runtime::isFpm()) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');

            if (app('request')->server('REQUEST_METHOD') === 'OPTIONS') {
                http_response_code(204);
                return '';
            }
        }

        return $next($request);
    }
}
```

### 请求日志中间件

```php
class RequestLogMiddleware implements MiddlewareInterface
{
    public function handle($request, \Closure $next)
    {
        $startTime = microtime(true);

        $response = $next($request);

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        Log::info("Request completed in {$elapsed}ms");

        return $response;
    }
}
```

## MiddlewarePipeline API

| 方法 | 说明 |
|------|------|
| `pipe($middleware)` | 添加中间件到管道 |
| `process($request, $handler)` | 执行中间件管道 |
| `count()` | 获取中间件数量 |

## RouteManager 中间件 API

| 方法 | 说明 |
|------|------|
| `middleware($middleware)` | 注册全局中间件 |
| `middlewares($middlewares)` | 批量注册全局中间件 |
| `registerMiddleware($name, $middleware)` | 注册路由级中间件别名 |
| `getRouteMiddleware($name)` | 获取路由级中间件 |
| `handlerMiddleware($handler, $names)` | 为指定 handler 绑定路由级中间件 |
