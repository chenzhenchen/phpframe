# 路由系统

PHPFrame 使用 `nikic/fast-route` 作为路由引擎，通过 `Route` 门面提供简洁的注册 API。

## 注册路由

路由定义在 `routes/default.php`（HTTP）和 `routes/shell.php`（Shell）中。

### HTTP 路由

```php
use PHPFrame\Facades\Route;
use App\Controllers\Default\DefaultController;

// 闭包处理器
Route::get('/', function() {
    return 'Hello World';
});

// 控制器方法（推荐，数组格式）
Route::get('/users', [UserController::class, 'listAction']);
Route::post('/users', [UserController::class, 'createAction']);
Route::put('/users/{id}', [UserController::class, 'updateAction']);
Route::delete('/users/{id}', [UserController::class, 'deleteAction']);

// 所有 HTTP 方法
Route::get($path, $handler);
Route::post($path, $handler);
Route::put($path, $handler);
Route::delete($path, $handler);
Route::patch($path, $handler);
Route::options($path, $handler);
Route::any($path, $handler);
```

### 路由参数

```php
// 必选参数
Route::get('/users/{id}', [UserController::class, 'showAction']);

// 在控制器中通过 getParam 获取
protected function showAction()
{
    $id = $this->getParam('id');
}
```

### 路由组

```php
Route::group('/api', function () {
    Route::get('/users', [UserController::class, 'listAction']);
    Route::post('/users', [UserController::class, 'createAction']);
    Route::get('/users/{id}', [UserController::class, 'showAction']);
});
// 匹配: /api/users, /api/users/123
```

### Shell 路由

```php
// routes/shell.php
Route::shell('database/tables', [DatabaseShell::class, 'tablesAction']);
Route::shell('database/describe', [DatabaseShell::class, 'describeAction']);
```

执行：

```bash
php shell.php database/tables
php shell.php database/describe
```

Shell 路由支持参数传递：

```bash
php shell.php database/describe table=users
```

在 Shell 控制器中获取参数：

```php
protected function describeAction()
{
    $tableName = $this->getParam('table');
}
```

## 路由级中间件

可以为指定的控制器方法（handler）绑定中间件，详见 [middleware.md](middleware.md)：

```php
use PHPFrame\Facades\Route;

// 注册中间件别名
Route::registerMiddleware('auth', new AuthMiddleware());

// 绑定到指定 handler
Route::handlerMiddleware('App\Controllers\UserController@profile', ['auth']);
```

> 注意：`Route::middleware()`、`Route::registerMiddleware()`、`Route::handlerMiddleware()` 是 Route Facade 的代理方法，内部通过 `app('router')` 转发到 RouteManager 实例。

## 路由分发流程

### FPM 模式

```
HTTP 请求 → index.php → Application::runFpm()
  → RouteManager::handleFpmRequest($method, $uri, $app)
    → FastRoute 分发
    → executeHandler() / executeWithMiddleware()
    → 控制器方法返回响应
```

### CLI 模式

```
ReactPHP 接收请求 → Application::runCli()
  → ReactiveRequestHandler（从容器获取 RouteManager）
    → RouteManager::handleCliRequest($request)
      → FastRoute 分发
      → executeHandler() / executeWithMiddleware()
      → 返回 ReactResponse
```

> CLI 模式下 `ReactiveRequestHandler` 复用容器中的 `RouteManager` 实例，确保全局中间件和路由级中间件与 FPM 模式一致。

### Shell 模式

```
命令行参数 → shell.php → Application::runShell()
  → RouteManager::handleShellRequest($command, $args)
    → FastRoute 分发（SHELL 方法）
    → executeHandler()
    → 输出结果
```

## 控制器 before() 钩子

如果控制器定义了 `before()` 方法，它会在 action 方法之前自动调用。`before()` 中抛出的异常会正常传播到异常处理器，不会被静默吞掉：

```php
class UserController extends BaseController
{
    public function before()
    {
        // 权限检查等前置逻辑
        if (!$this->hasParam('token')) {
            throw new \InvalidArgumentException('Token is required');
        }
    }

    public function profileAction()
    {
        // before() 通过后才会执行
    }
}
```

> 注意：旧版本中 `before()` 抛出的异常会被静默吞掉，新版本已修复此行为，异常会正常传播。

## RouteManager API

`RouteManager` 是路由分发核心，由框架自动创建并注册到容器（`app('router')`），通常不需要直接操作。

| 方法 | 说明 |
|------|------|
| `handleFpmRequest($method, $uri, $app)` | FPM 模式请求处理 |
| `handleCliRequest($request)` | CLI 模式请求处理 |
| `handleShellRequest($command, $args)` | Shell 模式请求处理 |
| `middleware($middleware)` | 注册全局中间件 |
| `middlewares($middlewares)` | 批量注册全局中间件 |
| `registerMiddleware($name, $middleware)` | 注册路由级中间件别名 |
| `getRouteMiddleware($name)` | 获取路由级中间件 |
| `handlerMiddleware($handler, $names)` | 为指定 handler 绑定路由级中间件 |
