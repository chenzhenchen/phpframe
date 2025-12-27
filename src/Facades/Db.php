<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * 数据库门面类
 * 使用方式：Db::table('users')->get()
 * 
 * @method static \Illuminate\Database\Query\Builder table(string $table) 获取查询构建器实例
 * @method static \Illuminate\Database\Connection connection(string $name = null) 获取数据库连接
 * @method static array select(string $sql, array $bindings = []) 执行原生SQL查询
 * @method static object|null selectOne(string $sql, array $bindings = []) 执行原生SQL查询并返回第一条记录
 * @method static bool statement(string $sql, array $bindings = []) 执行原生SQL语句
 * @method static bool insert(string $sql, array $bindings = []) 执行插入操作
 * @method static bool delete(string $sql, array $bindings = []) 执行删除操作
 * @method static bool tableExists(string $tableName) 检查表是否存在
 * @method static array getTableInfo(string $tableName) 获取表结构信息
 * @method static string getVersion() 获取数据库版本
 * @method static array getConnectionInfo() 获取数据库连接信息
 * @method static bool isConnected() 检查数据库连接状态
 * @method static void beginTransaction() 开始事务
 * @method static void commit() 提交事务
 * @method static void rollback() 回滚事务
 * @method static mixed transaction(callable $callback) 事务包装器
 */
class Db extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'db';
    }
}