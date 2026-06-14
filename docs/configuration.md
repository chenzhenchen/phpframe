# 配置管理

PHPFrame 使用 `ConfigManager` 管理所有配置，支持自动扫描、环境覆盖和点号访问。

## 自动加载机制

`ConfigManager` 会自动扫描 `config/` 目录下所有 `.php` 文件，以文件名作为配置组键名：

```
config/app.php       → config('app.name')
config/database.php  → config('database.connections.default.host')
config/cache.php     → config('cache.default')
config/log.php       → config('log.path')
```

新增配置文件无需修改任何代码，放入 `config/` 目录即可自动加载。

> 配置文件必须返回数组，否则会抛出 `RuntimeException`。

## 环境特定配置

支持环境覆盖文件，命名规则为 `{文件名}.{环境}.php`：

```
config/app.php                # 基础配置
config/app.production.php     # 生产环境覆盖
config/app.local.php          # 本地环境覆盖
```

环境由 `APP_ENV` 环境变量决定。当 `APP_ENV=production` 时，`app.production.php` 中的配置会深度覆盖 `app.php`。

### 深度覆盖规则

环境配置使用深度合并（deep merge）而非递归合并，确保同名键被正确覆盖：

```php
// config/app.php
return [
    'debug' => true,
    'name' => 'myapp',
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
    ],
];

// config/app.production.php
return [
    'debug' => false,
    'database' => [
        'host' => 'prod-db.example.com',
    ],
];

// 合并结果（production 环境）
[
    'debug' => false,           // 覆盖
    'name' => 'myapp',          // 保留
    'database' => [
        'host' => 'prod-db.example.com',  // 覆盖
        'port' => 3306,                   // 保留
    ],
]
```

> 注意：这与 `array_merge_recursive` 不同，后者会将相同字符串键的值合并为数组而非覆盖。

## 访问配置

### 全局函数

```php
// 获取配置值
config('app.name');                          // 'MyApp'
config('database.connections.default.host');  // '127.0.0.1'
config('app.debug', false);                  // 带默认值

// 获取所有配置
config();
```

### 门面方式

```php
use PHPFrame\Facades\Config;

Config::get('app.name');
Config::get('database.connections.default.host');
Config::has('app.name');
Config::all();
```

### 容器方式

```php
$config = app('config');
$config->get('app.name');
$config->set('app.custom_key', 'value');
$config->has('app.name');
```

## 动态修改配置

```php
// 运行时修改（不持久化），通过 ConfigManager
app('config')->set('app.debug', true);
```

> 注意：`config()` 全局函数仅支持获取配置值，不支持设置。`Config` Facade 也仅代理了 `get`、`has`、`all` 方法。如需运行时修改配置，请通过容器直接操作 ConfigManager。

## 环境变量

配置文件中推荐使用 `env()` 函数读取 `.env` 文件中的值：

```php
// config/database.php
return [
    'default' => env('DB_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
```

## 配置文件参考

### app.php

```php
return [
    'name' => env('APP_NAME', 'PHPFrame'),
    'env' => env('APP_ENV', 'prod'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', ''),
    'key' => env('APP_KEY', ''),
    'secret' => env('APP_SECRET', ''),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
];
```

### database.php

```php
return [
    'default' => env('DB_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ],
    ],
];
```

### cache.php

```php
return [
    'default' => env('CACHE_DRIVER', 'redis'),
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => runtime_path('cache'),
        ],
        'redis' => [
            'driver' => 'redis',
            'client' => env('REDIS_CLIENT', 'predis'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', ''),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'phpframe'),
];
```

### log.php

```php
return [
    'driver' => 'file',
    'path' => runtime_path('logs'),
    'level' => 'info',
    'format' => 'Y-m-d',
    'filename' => 'phpframe',
];
```

### exception.php

```php
return [
    // 自定义异常处理器（可选，不配置则使用框架内置处理器）
    // 'handler' => App\Library\ExceptionHandler::class,
];
```

## ConfigManager API

| 方法 | 说明 |
|------|------|
| `get($key, $default)` | 获取配置值，支持点号分隔 |
| `set($key, $value)` | 设置配置值 |
| `has($key)` | 检查配置是否存在 |
| `all()` | 获取所有配置 |
| `merge($config)` | 合并配置数组 |
| `loadFile($path, $key)` | 手动加载单个配置文件 |
| `reload()` | 重新加载所有配置文件 |
| `getEnvironment()` | 获取当前环境名 |
| `setEnvironment($env)` | 设置环境名 |
