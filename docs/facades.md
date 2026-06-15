# 门面系统

门面（Facade）提供对容器中服务的静态调用代理，使代码更简洁。

## 内置门面

| 门面 | 服务 ID | 说明 |
|------|---------|------|
| `Log` | `logger` | 日志 |
| `Db` | `db` | 数据库 |
| `Cache` | `cache` | 缓存 |
| `App` | `app` | 应用容器 |
| `Config` | `config` | 配置 |
| `Route` | - | 路由注册（直接操作 RouteCollector，中间件方法延迟注册到 RouteManager） |
| `Hash` | `hash` | 哈希 |
| `Redis` | `redis` | Redis 连接实例（含 db()/resetDb() 数据库切换方法） |
| `Request` | `request` | 请求（含扩展方法：isAjax/isPost/isGet/isDelete/isPut/isPatch/getHeader/getUserAgent/getReferer/getContentType/getClientIpAdvanced/getRealClientIp/setParams） |

## 使用方式

```php
use PHPFrame\Facades\Log;
use PHPFrame\Facades\Db;
use PHPFrame\Facades\Cache;
use PHPFrame\Facades\App;
use PHPFrame\Facades\Config;

// 日志
Log::info('message');
Log::error('error', ['context' => 'data']);

// 数据库
$users = Db::table('users')->get();
Db::beginTransaction();

// 缓存
Cache::set('key', 'value', 3600);
$value = Cache::get('key');

// 应用
App::get('db');         // 从容器获取服务
App::has('cache');      // 检查服务是否存在
App::set('my', fn() => new MyService());  // 注册服务

// 配置
Config::get('app.name');
Config::has('app.debug');
```

## 创建自定义门面

```php
namespace App\Facades;

use PHPFrame\Facade;

class Payment extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'payment'; // 对应容器中的服务 ID
    }
}
```

注册服务到容器：

```php
app()->set('payment', function ($c) {
    return new PaymentService($c->get('config'));
});
```

使用：

```php
use App\Facades\Payment;

Payment::charge($amount);
Payment::refund($transactionId);
```

## 门面上下文

门面支持上下文数据注入，主要用于日志门面的上下文传递：

```php
use PHPFrame\Facades\Log;

// 设置上下文（自动附加到日志消息中）
Log::setContext(['request_id' => $uuid, 'user_id' => $userId]);

// 后续日志自动携带上下文
Log::info('Processing order');
// 输出: Processing order {"request_id":"...","user_id":123}

// 清除上下文
Log::clearContext();
```

> 每个门面类的上下文是独立隔离的，`Log::setContext()` 不会影响 `Cache` 或其他门面的上下文。

## Facade 基类 API

| 方法 | 说明 |
|------|------|
| `getFacadeAccessor()` | 获取服务 ID（子类必须实现） |
| `resolveFacadeInstance($id)` | 从容器解析服务实例 |
| `setContext($context)` | 设置门面上下文 |
| `getContext()` | 获取门面上下文 |
| `clearContext()` | 清除门面上下文 |

> 注意：`Log::getLogger()` 是 Log 门面特有的方法，不是 Facade 基类方法。

## Route 门面的延迟注册机制

`Route` 门面的中间件方法（`middleware()`、`registerMiddleware()`、`handlerMiddleware()`）采用延迟注册机制：调用时先将中间件暂存到内部队列，待 `RouteManager` 创建后由框架自动调用 `Route::applyPendingMiddlewares()` 统一应用。

```
路由文件中调用 Route::middleware()
  → 暂存到 $pendingMiddlewares 队列
  → Application 创建 RouteManager 后
  → Route::applyPendingMiddlewares($routeManager)
  → 遍历队列，逐个调用 $routeManager->middleware()
  → 清空暂存队列
```

这样设计是因为路由文件在应用启动早期加载，此时 `RouteManager` 尚未创建。延迟注册确保了调用顺序的正确性，开发者无需关心 `RouteManager` 的初始化时机。

> `Route::applyPendingMiddlewares()` 由框架内部自动调用，通常不需要手动调用。

门面通过 `__callStatic` 魔术方法将静态调用转发到容器中的服务实例：

```
Log::info('message')
  → Facade::__callStatic('info', ['message'])
  → 从容器获取 'logger' 服务
  → 调用 $logger->info('message')
```
