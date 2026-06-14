# 辅助函数

PHPFrame 提供一组全局辅助函数，简化常见操作。

## 应用相关

### `app($id = null)`

获取 Application 实例或从容器解析服务：

```php
// 获取 Application 实例
$app = app();

// 从容器获取服务
$db = app('db');
$cache = app('cache');
$logger = app('logger');
```

### `config($key = null, $default = null)`

获取配置值：

```php
// 获取配置
$name = config('app.name');
$host = config('database.connections.default.host', '127.0.0.1');

// 获取所有配置
$all = config();
```

> 注意：`config()` 函数仅用于获取配置值。如需运行时修改配置，请使用 `app('config')->set('key', 'value')`。

## 路径相关

### `root_path($path = '')`

项目根目录：

```php
root_path();            // /var/www/my-project
root_path('composer.json'); // /var/www/my-project/composer.json
```

### `config_path($path = '')`

config 目录：

```php
config_path();          // /var/www/my-project/config
config_path('app.php'); // /var/www/my-project/config/app.php
```

### `runtime_path($path = '')`

runtime 目录（日志、缓存等）：

```php
runtime_path();         // /var/www/my-project/runtime
runtime_path('logs');   // /var/www/my-project/runtime/logs
```

### `public_path($path = '')`

public 目录：

```php
public_path();          // /var/www/my-project/public
public_path('index.php'); // /var/www/my-project/public/index.php
```

### `database_path($path = '')`

database 目录：

```php
database_path();            // /var/www/my-project/database
database_path('migrations'); // /var/www/my-project/database/migrations
```

### `resource_path($path = '')`

resources 目录：

```php
resource_path();            // /var/www/my-project/resources
resource_path('templates'); // /var/www/my-project/resources/templates
```

## 环境变量

### `env($key, $default = null)`

从 `$_ENV` / `$_SERVER` 读取环境变量：

```php
$debug = env('APP_DEBUG', false);
$dbHost = env('DB_HOST', '127.0.0.1');
```

## 日志

### `logger()`

获取 Logger 实例：

```php
logger()->info('User logged in');
logger()->error('Something went wrong', ['context' => $data]);
```

## 其他

### `value($value)`

如果值是闭包则执行，否则直接返回：

```php
value('hello');            // 'hello'
value(fn() => 'hello');    // 'hello'
```

### `dd(...$vars)`

调试函数：打印变量并终止脚本：

```php
dd($user, $orders);
```

### `array_get($array, $key, $default = null)`

使用点号表示法从数组中获取值：

```php
$data = ['database' => ['host' => '127.0.0.1']];
$host = array_get($data, 'database.host'); // '127.0.0.1'
```

## 请求隔离

### `isolate_request($force = false)`

执行请求级状态隔离（常驻内存模式下清除上一个请求的残留状态）：

```php
// 仅在 CLI 模式下执行
isolate_request();

// 强制执行（忽略模式检查）
isolate_request(true);
```

### `isolate_service($serviceId)`

隔离单个服务：

```php
isolate_service('db');
```

### `register_isolatable_service($serviceId, $class, $methods, $description)`

注册需要隔离的服务：

```php
register_isolatable_service('my_service', MyService::class, ['reset'], '我的服务');
```

### `get_isolation_report()`

获取隔离状态报告：

```php
$report = get_isolation_report();
```
