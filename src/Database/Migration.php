<?php

namespace PHPFrame\Database;

use Exception;

class Migration
{
    /**
     * @var string 迁移文件存储目录
     * Migration file storage directory
     */
    protected string $migrationsPath;
    
    /**
     * @var string 迁移记录表名
     * Migration record table name
     */
    protected string $migrationTable;
    
    /**
     * @var DatabaseManager 数据库实例
     * Database instance
     */
    protected DatabaseManager $db;
    
    /**
     * 构造函数
     * Constructor
     */
    public function __construct()
    {
        $this->db = app('db');
        
        // 从配置文件读取迁移配置
        // Read migration configuration from config file
        $migrationsConfig = config('database.migrations', [
            'table' => 'migration',
            'path' => ROOT_PATH . '/database/migrations',
            'enable' => true
        ]);
        
        // 设置迁移表名
        // Set migration table name
        $this->migrationTable = $migrationsConfig['table'] ?? 'migration';
        
        // 设置迁移文件路径
        // Set migration file path
        $this->migrationsPath = $migrationsConfig['path'] ?? ROOT_PATH . '/database/migrations';
        
        // 确保迁移目录存在
        // Ensure migration directory exists
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }
        
        // 确保迁移记录表存在
        // Ensure migration record table exists
        $this->ensureMigrationTableExists();
    }
    
    /**
     * 运行所有未执行的迁移
     * Run all pending migrations
     * 
     * @return array 执行结果信息
     * @return array Execution result information
     */
    public function migrate(): array
    {
        // 检查迁移功能是否启用
        // Check if migration feature is enabled
        $migrationsConfig = config('database.migrations', []);
        if (isset($migrationsConfig['enable']) && !$migrationsConfig['enable']) {
            return ["Database migration feature has been disabled"];
        }
        
        $notes = [];
        $notes[] = "Starting database migration...";
        
        // 获取所有迁移文件
        // Get all migration files
        $migrationFiles = $this->getMigrationFiles();
        
        // 获取已运行的迁移
        // Get already executed migrations
        $ranMigrations = $this->getRanMigrations();
        
        // 计算需要运行的迁移
        // Calculate pending migrations
        $pendingMigrations = array_diff($migrationFiles, $ranMigrations);
        
        if (empty($pendingMigrations)) {
            $notes[] = "No pending migrations to run";
            return $notes;
        }
        
        // 按文件名排序
        // Sort by filename
        sort($pendingMigrations);
        
        $batch = $this->getNextBatchNumber();
        
        foreach ($pendingMigrations as $migration) {
            try {
                $notes[] = "Running migration: {$migration}";
                
                // 执行迁移
                // Execute migration
                $this->runMigration($migration, $batch);
                
                // 记录迁移
                // Log migration
                $this->logMigration($migration, $batch);
                
                $notes[] = "Migration completed: {$migration}";
                
            } catch (Exception $e) {
                $notes[] = "Migration failed: {$migration} - " . $e->getMessage();
                throw $e;
            }
        }
        
        $notes[] = "All migrations completed";
        return $notes;
    }
    
    /**
     * 回滚最后一次迁移
     * Rollback the last migration
     * 
     * @return array 执行结果信息
     * @return array Execution result information
     */
    public function rollback(): array
    {
        // 检查迁移功能是否启用
        // Check if migration feature is enabled
        $migrationsConfig = config('database.migrations', []);
        if (isset($migrationsConfig['enable']) && !$migrationsConfig['enable']) {
            return ["Database migration feature has been disabled"];
        }
        
        $notes = [];
        $notes[] = "Starting database migration rollback...";
        
        // 获取最后一次迁移批次
        // Get the last migration batch
        $batch = $this->getLastBatchNumber();
        
        if (!$batch) {
            $notes[] = "No migrations to rollback";
            return $notes;
        }
        
        // 获取该批次的所有迁移
        // Get all migrations in this batch
        $migrations = $this->getMigrationsByBatch($batch);
        
        // 按文件名倒序回滚
        // Rollback in reverse filename order
        rsort($migrations);
        
        foreach ($migrations as $migration) {
            try {
                $notes[] = "Rolling back migration: {$migration}";
                
                // 执行回滚
                // Execute rollback
                $this->rollbackMigration($migration);
                
                // 删除迁移记录
                // Delete migration record
                $this->deleteMigrationRecord($migration);
                
                $notes[] = "Rollback completed: {$migration}";
                
            } catch (Exception $e) {
                $notes[] = "Rollback failed: {$migration} - " . $e->getMessage();
                throw $e;
            }
        }
        
        $notes[] = "Migration rollback completed";
        return $notes;
    }
    
    /**
     * 重置所有迁移
     * Reset all migrations
     * 
     * @return array 执行结果信息
     * @return array Execution result information
     */
    public function reset(): array
    {
        // 检查迁移功能是否启用
        $migrationsConfig = config('database.migrations', []);
        if (isset($migrationsConfig['enable']) && !$migrationsConfig['enable']) {
            return ["Database migration feature has been disabled"];
        }
        
        $notes = [];
        $notes[] = "Starting database migration reset...";
        
        // 获取所有已运行的迁移（按批次倒序）
        $migrations = $this->getAllRanMigrations();
        
        if (empty($migrations)) {
            $notes[] = "No migrations to reset";
            return $notes;
        }
        
        // 按批次倒序回滚
        $batches = array_unique(array_column($migrations, 'batch'));
        rsort($batches);
        
        foreach ($batches as $batch) {
            $batchMigrations = array_filter($migrations, function($m) use ($batch) {
                return $m->batch == $batch;
            });
            
            // 按文件名倒序回滚
            usort($batchMigrations, function($a, $b) {
                return strcmp($b->migration, $a->migration);
            });
            
            foreach ($batchMigrations as $migration) {
                try {
                    $notes[] = "Rolling back migration: {$migration->migration}";
                    
                    // 执行回滚
                    $this->rollbackMigration($migration->migration);
                    
                    // 删除迁移记录
                    $this->deleteMigrationRecord($migration->migration);
                    
                    $notes[] = "Rollback completed: {$migration->migration}";
                    
                } catch (Exception $e) {
                    $notes[] = "Rollback failed: {$migration->migration} - " . $e->getMessage();
                    throw $e;
                }
            }
        }
        
        $notes[] = "Database migration reset completed";
        return $notes;
    }
    
    /**
     * 刷新数据库（重置并重新运行所有迁移）
     * Refresh database (reset and re-run all migrations)
     * 
     * @return array 执行结果信息
     */
    public function refresh(): array
    {
        // 检查迁移功能是否启用
        $migrationsConfig = config('database.migrations', []);
        if (isset($migrationsConfig['enable']) && !$migrationsConfig['enable']) {
            return ["Database migration feature has been disabled"];
        }
        
        $notes = [];
        
        // 重置
        $resetNotes = $this->reset();
        $notes = array_merge($notes, $resetNotes);
        
        // 重新运行迁移
        $migrateNotes = $this->migrate();
        $notes = array_merge($notes, $migrateNotes);
        
        return $notes;
    }
    
    /**
     * 显示迁移状态
     * Show migration status
     * 
     * @return array 迁移状态信息
     */
    public function status(): array
    {
        // 检查迁移功能是否启用
        $migrationsConfig = config('database.migrations', []);
        if (isset($migrationsConfig['enable']) && !$migrationsConfig['enable']) {
            return [];
        }
        
        $status = [];
        
        // 获取所有迁移文件
        $migrationFiles = $this->getMigrationFiles();
        
        // 获取已运行的迁移
        $ranMigrations = $this->getAllRanMigrations();
        
        // 构建状态数组
        foreach ($migrationFiles as $migration) {
            $ranMigration = array_filter($ranMigrations, function($m) use ($migration) {
                return $m->migration === $migration;
            });
            
            if (!empty($ranMigration)) {
                $ranMigration = reset($ranMigration);
                $status[] = [
                    'migration' => $migration,
                    'ran' => true,
                    'batch' => $ranMigration->batch
                ];
            } else {
                $status[] = [
                    'migration' => $migration,
                    'ran' => false,
                    'batch' => null
                ];
            }
        }
        
        return $status;
    }
    
    /**
     * 创建新的迁移文件
     * 
     * @param string $name 迁移名称
     * @return string 迁移文件路径
     */
    public function create(string $name): string
    {
        // 检查迁移功能是否启用
        $migrationsConfig = config('database.migrations', []);
        if (isset($migrationsConfig['enable']) && !$migrationsConfig['enable']) {
            throw new Exception("Database migration feature has been disabled");
        }
        
        // 生成时间戳
        $timestamp = date('Y_m_d_His');
        
        // 格式化迁移名称
        $formattedName = $this->formatMigrationName($name);
        
        // 生成文件名
        $filename = "{$timestamp}_{$formattedName}.php";
        $filepath = $this->migrationsPath . '/' . $filename;
        
        // 生成迁移文件内容
        $content = $this->generateMigrationContent($formattedName);
        
        // 写入文件
        if (file_put_contents($filepath, $content) === false) {
            throw new Exception("Failed to create migration file: {$filepath}");
        }
        
        return $filepath;
    }
    
    /**
     * 确保迁移记录表存在
     * Ensure migration table exists
     */
    protected function ensureMigrationTableExists(): void
    {
        if (!$this->db->tableExists($this->migrationTable)) {
            $this->db->statement("
                CREATE TABLE {$this->migrationTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL,
                    batch INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
    }
    
    /**
     * 获取所有迁移文件
     * Get all migration files
     * 
     * @return array 迁移文件名数组
     */
    protected function getMigrationFiles(): array
    {
        $files = [];
        
        if (is_dir($this->migrationsPath)) {
            $dirFiles = scandir($this->migrationsPath);
            
            foreach ($dirFiles as $file) {
                if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_.+\.php$/', $file)) {
                    $files[] = basename($file, '.php');
                }
            }
        }
        
        return $files;
    }
    
    /**
     * 获取已运行的迁移文件名
     * Get all ran migrations
     * 
     * @return array 已运行的迁移文件名数组
     */
    protected function getRanMigrations(): array
    {
        $result = $this->db->select("SELECT migration FROM {$this->migrationTable}");
        
        return array_column($result, 'migration');
    }
    
    /**
     * 获取所有已运行的迁移记录
     * Get all ran migrations
     * 
     * @return array 迁移记录数组
     */
    protected function getAllRanMigrations(): array
    {
        return $this->db->select("SELECT migration, batch FROM {$this->migrationTable} ORDER BY batch, migration");
    }
    
    /**
     * 获取下一个批次号
     * Get next batch number
     * 
     * @return int 批次号
     */
    protected function getNextBatchNumber(): int
    {
        $result = $this->db->selectOne("SELECT MAX(batch) as max_batch FROM {$this->migrationTable}");
        
        return ($result && $result->max_batch) ? $result->max_batch + 1 : 1;
    }
    
    /**
     * 获取最后一个批次号
     * Get last batch number
     * 
     * @return int|null 批次号或null
     */
    protected function getLastBatchNumber(): ?int
    {
        $result = $this->db->selectOne("SELECT MAX(batch) as max_batch FROM {$this->migrationTable}");
        
        return ($result && $result->max_batch) ? $result->max_batch : null;
    }
    
    /**
     * 获取指定批次的所有迁移
     * Get all migrations in a batch
     * 
     * @param int $batch 批次号
     * @return array 迁移文件名数组
     */
    protected function getMigrationsByBatch(int $batch): array
    {
        $result = $this->db->select("SELECT migration FROM {$this->migrationTable} WHERE batch = ?", [$batch]);
        
        return array_column($result, 'migration');
    }
    
    /**
     * 运行单个迁移
     * Run single migration
     * 
     * @param string $migration 迁移文件名
     * @param int $batch 批次号
     */
    protected function runMigration(string $migration, int $batch): void
    {
        $filepath = $this->migrationsPath . '/' . $migration . '.php';
        
        if (!file_exists($filepath)) {
            throw new Exception("迁移文件不存在: {$filepath}");
        }
        
        require_once $filepath;
        
        $className = $this->getMigrationClassName($migration);
        
        if (!class_exists($className)) {
            throw new Exception("迁移类不存在: {$className}");
        }
        
        $migrationInstance = new $className();
        
        if (method_exists($migrationInstance, 'up')) {
            $migrationInstance->up();
        }
    }
    
    /**
     * 回滚单个迁移
     * Rollback single migration
     * 
     * @param string $migration 迁移文件名
     */
    protected function rollbackMigration(string $migration): void
    {
        $filepath = $this->migrationsPath . '/' . $migration . '.php';
        
        if (!file_exists($filepath)) {
            throw new Exception("Migration file does not exist: {$filepath}");
        }
        
        require_once $filepath;
        
        $className = $this->getMigrationClassName($migration);
        
        if (!class_exists($className)) {
            throw new Exception("Migration class does not exist: {$className}");
        }
        
        $migrationInstance = new $className();
        
        if (method_exists($migrationInstance, 'down')) {
            $migrationInstance->down();
        }
    }
    
    /**
     * 记录迁移
     * Record migration
     * 
     * @param string $migration 迁移文件名
     * @param int $batch 批次号
     */
    protected function logMigration(string $migration, int $batch): void
    {
        $this->db->insert("INSERT INTO {$this->migrationTable} (migration, batch) VALUES (?, ?)", [$migration, $batch]);
    }
    
    /**
     * 删除迁移记录
     * Delete migration record
     * 
     * @param string $migration 迁移文件名
     */
    protected function deleteMigrationRecord(string $migration): void
    {
        $this->db->delete("DELETE FROM {$this->migrationTable} WHERE migration = ?", [$migration]);
    }
    
    /**
     * 格式化迁移名称
     * Format migration name
     * 
     * @param string $name 原始名称
     * @return string 格式化后的名称
     */
    protected function formatMigrationName(string $name): string
    {
        // 移除特殊字符，只保留字母、数字和下划线
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        
        // 转换为蛇形命名法
        $name = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $name));
        
        // 移除连续的下划线
        $name = preg_replace('/_+/', '_', $name);
        
        // 移除开头和结尾的下划线
        $name = trim($name, '_');
        
        return $name;
    }
    
    /**
     * 生成迁移文件内容
     * 
     * @param string $className 类名
     * @return string 文件内容
     */
    protected function generateMigrationContent(string $className): string
    {
        $className = $this->getMigrationClassName($className);
        
        return "<?php

use PHPFrame\Facades\Db;

class {$className}
{
    /**
     * 运行迁移
     * Run migration
     */
    public function up()
    {
        // 创建表或修改表结构的代码
        // 例如：
        // Db::statement('CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');
    }

    /**
     * 回滚迁移
     * Rollback migration
     */
    public function down()
    {
        // 回滚迁移的代码
        // 例如：
        // Db::statement('DROP TABLE IF EXISTS users');
    }
}";
    }
    
    /**
     * 获取迁移类名
     * Get migration class name
     * 
     * @param string $migration 迁移文件名
     * @return string 类名
     */
    protected function getMigrationClassName(string $migration): string
    {
        // 移除时间戳部分
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration);
        
        // 转换为帕斯卡命名法
        $name = str_replace('_', ' ', $name);
        $name = ucwords($name);
        $name = str_replace(' ', '', $name);
        
        return $name;
    }
}