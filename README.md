# PHPFrame

PHPFrame 是一个支持 FPM / CLI（ReactPHP 常驻内存）/ Shell 三模式的 PHP 框架，提供统一的控制器、路由、中间件、数据库、缓存、日志等核心能力。

## 特性

- **三模式统一**：FPM、CLI（ReactPHP）、Shell 共享同一套路由和控制器体系
- **中间件管道**：洋葱模型的中间件机制，支持全局中间件和路由级中间件
- **请求解耦**：Request 对象可脱离超全局变量独立使用，便于单元测试
- **依赖注入容器**：支持单例和原型服务、循环依赖检测、自动依赖解析
- **数据库抽象**：基于 Illuminate Database，支持查询缓存委托给 CacheManager
- **Monolog 日志**：统一使用 Monolog，支持 `Log::info()` 静态调用和管道格式请求日志，自动按日期轮转
- **多 Worker 进程**：CLI 模式支持 pcntl_fork 多进程，自动监控和重启，PID 文件实时更新
- **环境感知配置**：自动扫描 config 目录，支持 `app.production.php` 环境覆盖（深度覆盖而非递归合并）
- **统一异常处理**：内置 ExceptionHandler，可自定义覆盖
- **Fiber 并发**：基于 PHP Fiber 的 Task 类，支持并发、竞速（自动取消未完成 Fiber）、定时器等
- **门面系统**：`Log::info()`、`Db::table()`、`Cache::get()` 等静态调用风格，各门面上下文独立隔离

## 快速开始

### 安装

```bash
composer create-project phpframe-project/template my-project
cd my-project
cp .env.example .env
```

### 目录结构

```
my-project/
├── app/
│   └── Controllers/
│       ├── Default/          # HTTP 控制器
│       └── Shell/            # Shell 控制器
├── config/                   # 配置文件（自动加载）
│   ├── app.php
│   ├── app.production.php    # 生产环境覆盖（可选）
│   ├── database.php
│   ├── cache.php
│   ├── log.php
│   └── exception.php
├── routes/
│   ├── default.php           # HTTP 路由
│   └── shell.php             # Shell 路由
├── public/
│   └── index.php             # FPM 入口
├── cli.php                   # CLI 入口
└── shell.php                 # Shell 入口
```

### 启动服务

```bash
# FPM 模式
php -S localhost:8000 -t public/

# CLI 模式（ReactPHP 常驻内存，支持多 Worker）
php cli.php server start --host=0.0.0.0 --port=8000 --worker=4

# Shell 模式
php shell.php default/test
```

## 核心概念

### 中间件

```php
use PHPFrame\Facades\Route;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;

// 全局中间件
Route::middleware(new CorsMiddleware());

// 路由级中间件
Route::registerMiddleware('auth', new AuthMiddleware());
Route::handlerMiddleware('App\Controllers\UserController@profile', ['auth']);
```

### 依赖注入

```php
// 单例服务（默认）
app()->set('cache', function ($c) {
    return new CacheManager($c->get('repository'));
});

// 原型服务（每次解析返回新实例）
app()->prototype('request', function ($c) {
    return Request::createFromGlobals();
});

// 循环依赖检测（自动抛出异常）
// A → B → A 会抛出 "Circular dependency detected: A → B → A"
```

### 配置覆盖

```php
// config/app.php
return ['debug' => true, 'name' => 'myapp'];

// config/app.production.php（深度覆盖，同名键用后者覆盖前者）
return ['debug' => false];
// 结果: ['debug' => false, 'name' => 'myapp']
```

### 重定向

```php
// FPM 模式：设置 Location header 后返回，不再强制 exit
return $this->redirect('/login', 302);
// 中间件的后置逻辑仍会正常执行
```

## 文档

| 模块 | 文档 |
|------|------|
| 安装与项目结构 | [installation.md](docs/installation.md) |
| 配置管理 | [configuration.md](docs/configuration.md) |
| 容器与应用 | [container.md](docs/container.md) |
| 路由系统 | [routing.md](docs/routing.md) |
| 中间件 | [middleware.md](docs/middleware.md) |
| 请求处理 | [request.md](docs/request.md) |
| 响应处理 | [response.md](docs/response.md) |
| 数据库 | [database.md](docs/database.md) |
| 缓存 | [cache.md](docs/cache.md) |
| 日志系统 | [logger.md](docs/logger.md) |
| 门面系统 | [facades.md](docs/facades.md) |
| 常驻服务器 | [server.md](docs/server.md) |
| 异常处理 | [exceptions.md](docs/exceptions.md) |
| 辅助函数 | [helpers.md](docs/helpers.md) |
| 请求隔离 | [request-isolation.md](docs/request-isolation.md) |
| 异步任务 | [task.md](docs/task.md) |

## 环境要求

- PHP >= 8.1
- ext-pcntl（多 Worker 模式需要）
- ext-redis（Redis 缓存驱动需要）
- Composer 2.x

## License

MIT
