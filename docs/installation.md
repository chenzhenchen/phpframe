# 安装指南

## 环境要求

| 依赖 | 最低版本 | 说明 |
|------|----------|------|
| PHP | 8.1+ | 需要 Fiber 支持 |
| Composer | 2.x | 包管理 |
| ext-pcntl | - | 多 Worker 模式必须 |
| ext-redis | - | Redis 缓存驱动需要 |
| ext-pdo_mysql | - | MySQL 数据库驱动 |

## 创建项目

```bash
composer create-project phpframe/template my-project
cd my-project
```

## 环境配置

```bash
cp .env.example .env
```

编辑 `.env` 文件，配置数据库、缓存等：

```env
APP_NAME=MyApp
APP_ENV=local
APP_DEBUG=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mydb
DB_USERNAME=root
DB_PASSWORD=secret

CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## 目录结构

```
my-project/
├── app/
│   └── Controllers/
│       ├── Default/              # HTTP 控制器（继承 BaseController）
│       │   ├── Controller.php    # 控制器基类
│       │   └── DefaultController.php
│       └── Shell/                # Shell 控制器（继承 BaseShell）
│           ├── DefaultShell.php
│           └── DatabaseShell.php
├── config/                       # 配置文件（自动加载）
│   ├── app.php                   # 应用配置
│   ├── database.php              # 数据库配置
│   ├── cache.php                 # 缓存配置
│   ├── log.php                   # 日志配置
│   └── exception.php             # 异常处理配置
├── routes/
│   ├── default.php               # HTTP 路由定义
│   └── shell.php                 # Shell 路由定义
├── public/
│   └── index.php                 # FPM 入口文件
├── runtime/                      # 运行时目录（日志、缓存等）
├── cli.php                       # CLI 模式入口
├── shell.php                     # Shell 模式入口
├── .env                          # 环境变量
└── composer.json
```

## 三种运行模式

### FPM 模式

传统 PHP-FPM 或内置开发服务器：

```bash
# 开发服务器
php -S localhost:8000 -t public/

# 或配置 Nginx/Apache 指向 public/index.php
```

入口文件 `public/index.php`：

```php
<?php
if (!defined('APP_MODE')) {
    define('APP_MODE', 'fpm');
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
$app = new PHPFrame\Application();
$app->run();
```

### CLI 模式（ReactPHP 常驻内存）

```bash
# 前台运行
php cli.php server start --host=0.0.0.0 --port=8000 --worker=4

# 守护进程运行
php cli.php server start --host=0.0.0.0 --port=8000 --worker=4 --daemon
```

入口文件 `cli.php`：

```php
<?php
if (!defined('APP_MODE')) {
    define('APP_MODE', 'cli');
}
require_once __DIR__ . '/vendor/autoload.php';
$app = new PHPFrame\Application();
$app->run();
```

### Shell 模式

用于命令行任务（定时任务、数据迁移等）：

```bash
php shell.php default/test
php shell.php database/tables
```

入口文件 `shell.php`：

```php
<?php
if (!defined('APP_MODE')) {
    define('APP_MODE', 'shell');
}
require_once __DIR__ . '/vendor/autoload.php';
$app = new PHPFrame\Application();
$app->run();
```
