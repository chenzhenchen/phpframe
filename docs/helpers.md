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

获取或设置配置值：

```php
// 获取配置
$name = config('app.name');
$host = config('database.connections.default.host', '127.0.0.1');

// 获取所有配置
$all = config();
```

## 路径相关

### `base_path($path = '')`

项目根目录：

```php
base_path();            // /var/www/my-project
base_path('composer.json'); // /var/www/my-project/composer.json
```

### `app_path($path = '')`

app 目录：

```php
app_path();             // /var/www/my-project/app
app_path('Controllers'); // /var/www/my-project/app/Controllers
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
