# 数据库

PHPFrame 基于 `illuminate/database`（Eloquent ORM）提供数据库能力，通过 `DatabaseManager` 封装了连接管理、查询缓存和事务。

## 配置

参考 `config/database.php`，支持 MySQL、PostgreSQL、SQLite、SQL Server。

## 基本使用

### 门面方式

```php
use PHPFrame\Facades\Db;

// 查询构建器
$users = Db::table('users')->where('active', 1)->get();

// 原生 SQL
$users = Db::select('SELECT * FROM users WHERE active = ?', [1]);
$user = Db::selectOne('SELECT * FROM users WHERE id = ?', [$id]);

// 插入
Db::insert('INSERT INTO users (name, email) VALUES (?, ?)', ['John', 'john@example.com']);

// 更新
$affected = Db::update('UPDATE users SET name = ? WHERE id = ?', ['Jane', 2]);

// 删除
Db::delete('DELETE FROM users WHERE id = ?', [3]);
```

### 容器方式

```php
$db = app('db');

$db->select('SELECT * FROM users');
$db->table('users')->where('id', 1)->first();
```

## 查询构建器

```php
// SELECT
$users = Db::table('users')
    ->select('name', 'email')
    ->where('active', 1)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// INSERT
Db::table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// UPDATE
Db::table('users')->where('id', 1)->update(['name' => 'Jane']);

// DELETE
Db::table('users')->where('id', 1)->delete();
```

## 事务

```php
// 手动事务
Db::beginTransaction();
try {
    Db::table('users')->insert([...]);
    Db::table('orders')->insert([...]);
    Db::commit();
} catch (\Exception $e) {
    Db::rollback();
    throw $e;
}

// 闭包事务（自动提交/回滚）
Db::transaction(function () {
    Db::table('users')->insert([...]);
    Db::table('orders')->insert([...]);
});
```

## 查询缓存

DatabaseManager 支持查询结果缓存，优先委托给 CacheManager（Redis/File），降级到进程内数组。

### 自动缓存

SELECT 查询结果会自动缓存（默认 60 秒），INSERT/UPDATE/DELETE 操作会自动清除缓存。缓存判断逻辑仅检查 SQL 是否以 `SELECT` 开头（不区分大小写），简洁高效：

```php
// 第一次查询：数据库查询 + 缓存
$users = Db::select('SELECT * FROM users WHERE active = ?', [1]);

// 60 秒内再次查询：从缓存读取
$users = Db::select('SELECT * FROM users WHERE active = ?', [1]);

// 写入操作会自动清除查询缓存
Db::insert('INSERT INTO users ...');
```

### 配置缓存

```php
$db = app('db');

// 设置缓存 TTL（秒）
$db->setQueryCacheTTL(300);

// 设置缓存前缀
$db->setQueryCachePrefix('myapp:db:');
```

### CacheManager 自动注入

Container 注册 `db` 服务时会自动注入 CacheManager，查询缓存自动使用 Redis/File 存储。如果 CacheManager 不可用，自动降级到进程内数组缓存。

## 表结构查询

```php
// 检查表是否存在
$exists = Db::tableExists('users');

// 获取表结构
$info = Db::getTableInfo('users');

// 获取数据库大小
$size = Db::getDatabaseSize();

// 获取数据库版本
$version = Db::getVersion();
```

> 注意：表名会进行安全校验，仅允许字母、数字、下划线和点号（`schema.table` 格式），防止 SQL 注入。

## 连接管理

```php
$db = app('db');

// 连接信息
$info = Db::getConnectionInfo();
// ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'mydb', ...]

// 检查连接状态
$connected = Db::isConnected();

// 断开连接
$db->disconnect();

// 重连
$db->reconnect();
```

## 持久连接

CLI 模式下自动启用持久连接：

```php
// 手动控制
$db->enablePersistentConnections(true);
```

## 查询日志

```php
$db = app('db');

// 开启查询日志
$db->enableQueryLog();

// 执行查询...

// 获取统计
$stats = $db->getStats();
// ['queries' => 10, 'transactions' => 2, 'execution_time' => 0.15, 'cache_hits' => 5, 'cache_misses' => 5]

// 重置统计
$db->resetStats();
```

## DatabaseManager API

| 方法 | 说明 |
|------|------|
| `select($sql, $bindings)` | 执行 SELECT 查询 |
| `selectOne($sql, $bindings)` | 查询单条记录 |
| `insert($sql, $bindings)` | 插入数据 |
| `update($sql, $bindings)` | 更新数据（返回影响行数） |
| `delete($sql, $bindings)` | 删除数据 |
| `statement($sql, $bindings)` | 执行任意 SQL |
| `table($table)` | 获取查询构建器 |
| `beginTransaction()` | 开始事务 |
| `commit()` | 提交事务 |
| `rollback()` | 回滚事务 |
| `transaction($callback)` | 闭包事务 |
| `tableExists($name)` | 检查表是否存在 |
| `getTableInfo($name)` | 获取表结构 |
| `getDatabaseSize()` | 获取数据库大小 |
| `getVersion()` | 获取数据库版本 |
| `setCacheManager($cache)` | 设置缓存管理器 |
| `setQueryCacheTTL($seconds)` | 设置缓存 TTL |
| `setQueryCachePrefix($prefix)` | 设置缓存前缀 |
| `enablePersistentConnections($bool)` | 启用持久连接 |
| `enableQueryLog()` | 开启查询日志 |
| `disableQueryLog()` | 关闭查询日志 |
| `getStats()` | 获取查询统计 |
| `resetStats()` | 重置查询统计 |
| `getConnectionInfo()` | 获取连接信息 |
| `isConnected()` | 检查连接状态 |
| `disconnect()` | 断开连接 |
| `reconnect()` | 重连（断开后重连） |
| `getCapsuleInstance()` | 获取 Capsule Manager 实例 |
