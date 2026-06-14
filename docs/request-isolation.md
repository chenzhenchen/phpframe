# 请求隔离

在 CLI（ReactPHP 常驻内存）模式下，多个请求共享同一进程空间，必须确保每个请求的状态相互隔离，避免数据污染。

## 问题

常驻内存模式下，如果不做隔离：

```php
// 请求 A 设置了参数
$controller->setCliParams(['user_id' => 1]);

// 请求 A 处理完成，但控制器实例可能被复用

// 请求 B 进来，可能读到请求 A 的残留参数
$userId = $this->getParam('user_id'); // 可能仍然是 1
```

## 自动隔离

请求隔离由框架在请求级别统一管理，不再在 `BaseController` 构造函数中执行。`BaseController` 仅负责初始化请求和响应对象：

```php
// BaseController 构造函数
public function __construct()
{
    $this->runtimeMode = Runtime::detect();
    $this->request = new Request();
    $this->response = new Response();
}
```

> 注意：旧版本在 `BaseController` 构造函数中调用 `RequestIsolationManager::isolateAll()`，这会导致每个控制器实例化都执行隔离操作。新版本已移除此行为，隔离应在请求生命周期入口处按需调用。

默认隔离的服务：

| 服务 ID | 隔离方法 | 说明 |
|---------|----------|------|
| `db` | `clearQueryLog`, `flushQueryLog` | 清除数据库查询日志 |
| `request` | `clearParams` | 清除请求参数 |

## 注册自定义隔离服务

```php
use PHPFrame\RequestIsolationManager;

RequestIsolationManager::registerService(
    'my_service',
    \App\Library\MyService::class,
    ['reset', 'clearCache'],
    '我的自定义服务'
);
```

## 注销隔离服务

```php
RequestIsolationManager::unregisterService('my_service');
```

## 手动隔离

```php
// 隔离所有已注册服务
$results = RequestIsolationManager::isolateAll();

// 隔离单个服务
$success = RequestIsolationManager::isolate('db');
```

## 隔离报告

```php
$report = RequestIsolationManager::getReport();
// [
//     'initialized' => true,
//     'services_count' => 2,
//     'services' => [...]
// ]
```

## RequestIsolationManager API

| 方法 | 说明 |
|------|------|
| `isolateAll()` | 隔离所有已注册服务 |
| `isolate($serviceId)` | 隔离单个服务 |
| `registerService($id, $class, $methods, $desc)` | 注册隔离服务 |
| `unregisterService($id)` | 注销隔离服务 |
| `getServices()` | 获取所有已注册服务 |
| `isInitialized()` | 检查是否已初始化 |
| `getReport()` | 获取隔离报告 |
| `reset()` | 重置状态（测试用） |

## 最佳实践

1. **不要在控制器中存储请求级状态**：使用 `$this->request` 存储参数，不要用类属性
2. **注册需要隔离的服务**：如果你的自定义服务有请求级状态，注册到 RequestIsolationManager
3. **FPM 模式无需关心**：FPM 每个请求独立进程，不存在状态污染问题
