# 常驻服务器

PHPFrame 基于 ReactPHP 实现常驻内存 HTTP 服务器，支持多 Worker 进程。

## 启动服务器

```bash
# 前台运行（开发调试）
php cli.php server start --host=0.0.0.0 --port=8000 --worker=4

# 守护进程运行
php cli.php server start --host=0.0.0.0 --port=8000 --worker=4 --daemon
```

## 多 Worker 进程

当 `--worker` 参数大于 1 且安装了 `ext-pcntl` 时，服务器会 fork 多个 Worker 进程：

```
主进程 (PID: 1000)
├── Worker #0 (PID: 1001) → ReactPHP 事件循环
├── Worker #1 (PID: 1002) → ReactPHP 事件循环
├── Worker #2 (PID: 1003) → ReactPHP 事件循环
└── Worker #3 (PID: 1004) → ReactPHP 事件循环
```

- 主进程负责监控 Worker，自动重启退出的 Worker
- 每个 Worker 独立运行 ReactPHP 事件循环
- pcntl 不可用时自动降级到单进程模式

## 服务器管理

```bash
# 停止服务器
php cli.php server stop

# 重启服务器
php cli.php server restart

# 重载 Worker（发送 SIGUSR1 信号）
php cli.php server reload
```

## 信号处理

| 信号 | 主进程行为 | Worker 行为 |
|------|-----------|-------------|
| `SIGTERM` | 停止所有 Worker，退出 | 停止事件循环，退出 |
| `SIGINT` | 同 SIGTERM | 同 SIGTERM |
| `SIGUSR1` | - | 重载配置（可自定义重载逻辑） |

## PID 文件

服务器运行时在 `runtime/logs/reactive_server.pid` 保存进程信息（JSON 格式）：

```json
{
    "master_pid": 1000,
    "worker_pids": [1001, 1002, 1003, 1004],
    "host": "0.0.0.0",
    "port": 8000,
    "worker_num": 4,
    "started_at": "2026-06-13 10:30:00"
}
```

> PID 文件会在 Worker 重启时实时更新，确保 `stop` 和 `forceStop` 命令能获取到最新的 Worker PID 列表。

## 停止服务器

`stop()` 和 `forceStop()` 方法会优先从内存中的 PID 列表获取 Worker PID。如果内存为空（如通过另一个进程执行 `stop` 命令），则从 PID 文件读取 Worker PID，确保服务器能被正确停止。

## 静态文件服务

CLI 模式下自动处理静态文件请求（css/js/图片/字体等），无需额外配置。

## 请求隔离

CLI 常驻内存模式下，每个请求结束后需要清理状态，避免请求间数据污染。`BaseController` 在 CLI/Shell 模式下自动调用 `RequestIsolationManager::isolateAll()` 进行状态隔离。

详见 [request-isolation.md](request-isolation.md)。

## ReactiveServerManager API

| 方法 | 说明 |
|------|------|
| `start()` | 启动服务器 |
| `stop()` | 停止服务器 |
| `forceStop()` | 强制停止 |
| `reload()` | 重载 Worker |
| `restart()` | 重启服务器 |
| `getStatus()` | 获取服务器状态 |

### getStatus() 返回值

```php
[
    'running' => true,
    'host' => '0.0.0.0',
    'port' => 8000,
    'worker_num' => 4,
    'master_pid' => 1000,
    'worker_pids' => [1001, 1002, 1003, 1004],
    'pid_file' => '/path/to/reactive_server.pid',
    'started_at' => '2026-06-13 10:30:00',
]
```
