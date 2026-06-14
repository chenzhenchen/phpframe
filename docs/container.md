# 容器与应用

## Application

`Application` 是框架的核心类，继承自 `Container`，负责初始化、服务注册和请求分发。

### 生命周期

```
new Application()
  → loadEnvironment()         # 加载 .env
  → registerCoreServices()    # 注册核心服务到容器
  → registerExceptionHandler() # 注册异常处理器
  → run()                     # 根据 APP_MODE 分发请求
```

### 获取 Application 实例

```php
// 全局函数
$app = app();

// 静态方法
$app = \PHPFrame\Application::getInstance();

// 在容器中
$app = app('app');
```

## Container（服务容器）

Container 是轻量级依赖注入容器，提供服务的注册、解析、单例管理、原型服务和循环依赖检测。

### 注册服务

```php
// 闭包注册（默认单例行为）
app()->set('my_service', function ($container) {
    return new MyService($container->get('db'));
});

// 实例注册（直接绑定，始终返回同一实例）
app()->set('my_service', new MyService());
```

### 原型服务

原型服务每次解析时都会调用工厂函数创建新实例，适用于需要独立状态的场景：

```php
// 注册原型服务
app()->prototype('request', function ($container) {
    return Request::createFromGlobals();
});

// 每次调用 get() 都会创建新实例
$req1 = app()->get('request');
$req2 = app()->get('request');
// $req1 !== $req2
```

与单例服务的区别：

| 类型 | 注册方式 | `get()` 行为 | 适用场景 |
|------|----------|-------------|----------|
| 单例 | `set($id, $instance)` | 返回缓存实例 | 数据库连接、配置管理器 |
| 单例 | `set($id, $factory)` | 首次调用工厂，后续返回缓存实例 | 大多数服务 |
| 原型 | `prototype($id, $factory)` | 每次调用工厂创建新实例 | 请求对象、临时处理器 |

### 循环依赖检测

容器自动检测循环依赖并抛出异常，避免无限递归。内部使用哈希映射实现 O(1) 查找，性能优于线性搜索：

```php
app()->set('A', function ($c) { return new A($c->get('B')); });
app()->set('B', function ($c) { return new B($c->get('A')); });

app()->get('A');
// 抛出异常: Circular dependency detected: A → B → A
```

### 解析服务

```php
// 通过 get
$db = app()->get('db');

// 通过 make（支持自动依赖注入）
$instance = app()->make(MyService::class);

// 通过全局函数
$db = app('db');
```

### 单例服务

容器中注册的闭包服务默认首次解析后缓存为单例。如需每次创建新实例，请使用 `prototype()` 方法。

### 移除服务

```php
// 移除已注册的服务
app()->unset('my_service');
```

### 核心服务列表

| 服务 ID | 类 | 说明 |
|---------|-----|------|
| `app` | Application | 应用实例自身 |
| `config` | ConfigManager | 配置管理器 |
| `request` | Request | 请求对象（CLI 模式下为原型服务） |
| `db` | Database\DatabaseManager | 数据库管理器 |
| `cache` | CacheManager | 缓存管理器 |
| `logger` | Logger | 日志管理器 |
| `router` | FastRoute Dispatcher | 路由分发器（FastRoute 实例，非 RouteManager） |
| `twig` | \Twig\Environment | 模板引擎 |
| `redis` | Redis Connection | Redis 连接实例（通过 redis.manager 获取） |
| `redis.manager` | RedisManager | Redis 管理器 |

### 检查服务是否存在

```php
if (app()->has('db')) {
    $db = app('db');
}
```

> 注意：`has()` 仅检查容器中已注册的服务，不会对任意类名返回 `true`。如需检查类是否可自动解析，请使用 `class_exists()`。

### 获取已注册服务列表

```php
$services = app()->getRegisteredServices();
```

## 注意事项

- `Container::getInstance()` 委托给 `Application::getInstance()`，确保全局只有一个容器实例
- 核心服务在 `Application::initialize()` 中仅注册一次，不会重复注册
- `app` 服务直接指向 Application 实例自身，`App::get()`、`App::set()`、`App::has()` 等门面方法调用的是 Container 上的方法
- `Application::initialize()` 支持多实例场景，每个 Application 实例独立初始化
- `request` 服务在 CLI 常驻内存模式下注册为原型服务，每次解析返回新实例，避免请求间状态污染
- 循环依赖检测使用哈希映射（`isset`）而非线性搜索（`in_array`），确保 O(1) 查找性能
