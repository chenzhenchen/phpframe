<?php

namespace PHPFrame\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PDO;
use PHPFrame\CacheManager;

/**
 * 数据库管理器
 * 统一管理数据库连接、查询缓存和事务
 */
class DatabaseManager
{
    protected $capsule;

    protected $connections = [];

    /**
     * 进程内查询缓存（降级方案，CacheManager不可用时使用）
     */
    protected $queryCache = [];

    protected $cachePrefix = 'db_query:';

    protected $cacheTTL = 60;

    protected $stats = [
        'queries' => 0,
        'transactions' => 0,
        'execution_time' => 0.0,
        'cache_hits' => 0,
        'cache_misses' => 0
    ];

    protected $loggingEnabled = false;

    protected $usePersistentConnections = false;

    protected $runtimeMode = 'fpm';

    /**
     * CacheManager 实例（可选，用于跨进程/跨请求共享缓存）
     */
    protected ?CacheManager $cacheManager = null;

    /**
     * 是否使用 CacheManager（有则用，无则降级到进程内数组）
     */
    protected bool $useExternalCache = false;

    public function __construct(Capsule $capsule)
    {
        $this->capsule = $capsule;
    }

    /**
     * 设置 CacheManager 实例
     * 调用此方法后，查询缓存将委托给 CacheManager（Redis/File等）
     * 不调用则降级到进程内数组缓存（行为与旧版完全一致）
     */
    public function setCacheManager(CacheManager $cacheManager): self
    {
        $this->cacheManager = $cacheManager;
        $this->useExternalCache = true;
        return $this;
    }

    public function setRuntimeMode(string $mode): self
    {
        $this->runtimeMode = $mode;
        return $this;
    }

    public function getRuntimeMode(): string
    {
        return $this->runtimeMode;
    }

    public function enablePersistentConnections(bool $enable = true): self
    {
        $this->usePersistentConnections = $enable;
        return $this;
    }

    public function setQueryCacheTTL(int $seconds): self
    {
        $this->cacheTTL = $seconds;
        return $this;
    }

    public function setQueryCachePrefix(string $prefix): self
    {
        $this->cachePrefix = $prefix;
        return $this;
    }

    public function connection(string $name = null): Connection
    {
        $key = $name ?? 'default';

        if (!isset($this->connections[$key])) {
            $this->connections[$key] = $this->capsule->getConnection($name);

            if ($this->usePersistentConnections) {
                $this->configurePersistentConnection($this->connections[$key]);
            }
        }

        return $this->connections[$key];
    }

    protected function configurePersistentConnection(Connection $connection): void
    {
        $driverName = $connection->getDriverName();

        if ($driverName === 'mysql') {
            $pdo = $connection->getPdo();
            try {
                $pdo->setAttribute(PDO::ATTR_PERSISTENT, true);
            } catch (\Exception $e) {
            }
        }
    }

    public function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
        $this->stats['transactions']++;
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollback(): void
    {
        $this->connection()->rollback();
    }

    public function transaction(callable $callback)
    {
        return $this->connection()->transaction($callback);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $cacheKey = $this->getCacheKey($sql, $bindings);

        if ($this->isCacheable($sql)) {
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                $this->stats['cache_hits']++;
                return $cached;
            }
            $this->stats['cache_misses']++;
        }

        $startTime = microtime(true);
        $connection = $this->connection();

        try {
            $result = $connection->select($sql, $bindings);
            $executionTime = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $executionTime);

            if ($this->isCacheable($sql)) {
                $this->saveToCache($cacheKey, $result);
            }

            return $result;
        } catch (QueryException $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        $result = $this->select($sql, $bindings);
        return $result[0] ?? null;
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $startTime = microtime(true);
        $connection = $this->connection();

        try {
            $result = $connection->statement($sql, $bindings);
            $this->logQuery($sql, $bindings, microtime(true) - $startTime);
            return $result;
        } catch (QueryException $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }

    public function insert(string $sql, array $bindings = []): bool
    {
        $startTime = microtime(true);
        $connection = $this->connection();

        try {
            $result = $connection->insert($sql, $bindings);
            $this->logQuery($sql, $bindings, microtime(true) - $startTime);
            $this->clearQueryCache();
            return $result;
        } catch (QueryException $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }

    public function update(string $sql, array $bindings = []): int
    {
        $startTime = microtime(true);
        $connection = $this->connection();

        try {
            $result = $connection->update($sql, $bindings);
            $this->logQuery($sql, $bindings, microtime(true) - $startTime);
            $this->clearQueryCache();
            return $result;
        } catch (QueryException $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }

    public function delete(string $sql, array $bindings = []): bool
    {
        $startTime = microtime(true);
        $connection = $this->connection();

        try {
            $result = $connection->delete($sql, $bindings);
            $this->logQuery($sql, $bindings, microtime(true) - $startTime);
            $this->clearQueryCache();
            return $result;
        } catch (QueryException $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }
    }

    public function tableExists(string $tableName): bool
    {
        $this->validateTableName($tableName);

        $connection = $this->connection();
        $driverName = $connection->getDriverName();

        $cacheKey = $this->getCacheKey('table_exists_' . $tableName, []);
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = false;
        switch ($driverName) {
            case 'mysql':
                $result = $connection->select("
                    SELECT COUNT(*) as count
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = ?
                ", [$tableName])[0]->count > 0;
                break;
            case 'pgsql':
                $result = $connection->select("
                    SELECT COUNT(*) as count
                    FROM information_schema.tables
                    WHERE table_name = ?
                ", [$tableName])[0]->count > 0;
                break;
            case 'sqlite':
                $result = $connection->select("
                    SELECT COUNT(*) as count
                    FROM sqlite_master
                    WHERE type = 'table' AND name = ?
                ", [$tableName])[0]->count > 0;
                break;
        }

        $this->saveToCache($cacheKey, $result, 300);
        return $result;
    }

    public function getTableInfo(string $tableName): array
    {
        $this->validateTableName($tableName);

        $connection = $this->connection();
        $driverName = $connection->getDriverName();

        $cacheKey = $this->getCacheKey('table_info_' . $tableName, []);
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = [];
        switch ($driverName) {
            case 'mysql':
                $result = $connection->select("DESCRIBE `{$tableName}`");
                break;
            case 'pgsql':
                $result = $connection->select("
                    SELECT column_name, data_type, is_nullable, column_default
                    FROM information_schema.columns
                    WHERE table_name = ?
                ", [$tableName]);
                break;
            case 'sqlite':
                $result = $connection->select("PRAGMA table_info(`{$tableName}`)");
                break;
        }

        $this->saveToCache($cacheKey, $result, 300);
        return $result;
    }

    public function getDatabaseSize(): ?string
    {
        $connection = $this->connection();
        $driverName = $connection->getDriverName();

        switch ($driverName) {
            case 'mysql':
                $result = $connection->select("
                    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                ");
                return $result[0]->size_mb ?? null;
            case 'pgsql':
                $result = $connection->select("
                    SELECT pg_database_size(current_database()) as size_bytes
                ");
                return round($result[0]->size_bytes / 1024 / 1024, 2) . ' MB';
            default:
                return null;
        }
    }

    public function getVersion(): string
    {
        return $this->connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = [
            'queries' => 0,
            'transactions' => 0,
            'execution_time' => 0.0,
            'cache_hits' => 0,
            'cache_misses' => 0
        ];
    }

    public function getConnectionInfo(): array
    {
        $connection = $this->connection();

        return [
            'driver' => $connection->getDriverName(),
            'host' => $connection->getConfig('host'),
            'database' => $connection->getDatabaseName(),
            'version' => $this->getVersion(),
            'size' => $this->getDatabaseSize(),
            'persistent' => $this->usePersistentConnections,
            'mode' => $this->runtimeMode
        ];
    }

    public function isConnected(): bool
    {
        try {
            $this->connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function disconnect(): void
    {
        foreach ($this->connections as $name => $connection) {
            $connection->disconnect();
        }
        $this->connections = [];
        $this->clearQueryCache();
    }

    public function reconnect(): void
    {
        $this->disconnect();
    }

    public function table(string $table)
    {
        return $this->connection()->table($table);
    }

    // ─── 缓存方法 ───

    protected function getCacheKey(string $sql, array $bindings): string
    {
        return $this->cachePrefix . md5($sql . json_encode($bindings));
    }

    protected function isCacheable(string $sql): bool
    {
        $sql = strtoupper(trim($sql));
        // SELECT开头的SQL不可能同时以INSERT/UPDATE/DELETE开头，无需额外检查
        return strpos($sql, 'SELECT') === 0;
    }

    /**
     * 从缓存获取数据
     * 优先使用 CacheManager（Redis/File），降级到进程内数组
     */
    protected function getFromCache(string $key)
    {
        if ($this->useExternalCache) {
            try {
                return $this->cacheManager->get($key);
            } catch (\Exception $e) {
                // CacheManager 异常时降级到进程内缓存
            }
        }

        // 降级：进程内数组缓存（与旧版行为一致）
        if (!isset($this->queryCache[$key])) {
            return null;
        }

        $cached = $this->queryCache[$key];
        if ($cached['expires'] < time()) {
            unset($this->queryCache[$key]);
            return null;
        }

        return $cached['data'];
    }

    /**
     * 保存数据到缓存
     * 优先使用 CacheManager（Redis/File），降级到进程内数组
     */
    protected function saveToCache(string $key, $data, int $ttl = null): void
    {
        $ttl = $ttl ?? $this->cacheTTL;

        if ($this->useExternalCache) {
            try {
                $this->cacheManager->set($key, $data, $ttl);
                return;
            } catch (\Exception $e) {
                // CacheManager 异常时降级到进程内缓存
            }
        }

        // 降级：进程内数组缓存（与旧版行为一致）
        $this->queryCache[$key] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];

        if (count($this->queryCache) > 1000) {
            $this->cleanupCache();
        }
    }

    /**
     * 清除查询缓存
     * CacheManager 模式下按前缀清除，进程内模式直接清空数组
     */
    protected function clearQueryCache(): void
    {
        if ($this->useExternalCache) {
            try {
                $this->cacheManager->deleteByPattern($this->cachePrefix . '*');
                return;
            } catch (\Exception $e) {
                // 降级
            }
        }

        $this->queryCache = [];
    }

    protected function cleanupCache(): void
    {
        $now = time();
        foreach ($this->queryCache as $key => $cached) {
            if ($cached['expires'] < $now) {
                unset($this->queryCache[$key]);
            }
        }
    }

    // ─── 安全方法 ───

    /**
     * 校验表名，防止SQL注入
     * 仅允许字母、数字、下划线、点号（schema.table格式）
     */
    protected function validateTableName(string $tableName): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $tableName)) {
            throw new \InvalidArgumentException("Invalid table name: {$tableName}");
        }
    }

    protected function logQuery(string $sql, array $bindings, float $executionTime, ?string $error = null): void
    {
        $this->stats['queries']++;
        $this->stats['execution_time'] += $executionTime;

        if ($this->loggingEnabled) {
            try {
                $logger = app('logger');
                if ($logger) {
                    $context = [
                        'sql' => $sql,
                        'bindings' => $bindings,
                        'time_ms' => round($executionTime * 1000, 2),
                    ];
                    if ($error) {
                        $context['error'] = $error;
                        $logger->error('Query failed', $context);
                    } else {
                        $logger->debug('Query executed', $context);
                    }
                }
            } catch (\Throwable $e) {
                // 日志记录失败时静默处理
            }
        }
    }

    public function enableQueryLog(): void
    {
        $this->loggingEnabled = true;
    }

    public function disableQueryLog(): void
    {
        $this->loggingEnabled = false;
    }

    public function getCapsuleInstance(): Capsule
    {
        return $this->capsule;
    }
}
