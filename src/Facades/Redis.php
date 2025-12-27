<?php

namespace PHPFrame\Facades;

use PHPFrame\Facade;

/**
 * Redis门面类
 * 使用方式：Redis::db(1)->get('key'), Redis::set('key', 'value')
 * 
 * @method static mixed get(string $key) 获取键值
 * @method static bool set(string $key, mixed $value, int $ttl = null) 设置键值
 * @method static bool del(string|array $key) 删除键
 * @method static bool exists(string $key) 检查键是否存在
 * @method static int expire(string $key, int $seconds) 设置过期时间
 * @method static int ttl(string $key) 获取剩余过期时间
 * @method static bool persist(string $key) 移除过期时间
 * @method static string|bool type(string $key) 获取键类型
 * @method static int incr(string $key) 递增整数值
 * @method static int incrby(string $key, int $increment) 按指定值递增
 * @method static int decr(string $key) 递减整数值
 * @method static int decrby(string $key, int $decrement) 按指定值递减
 * @method static int lpush(string $key, mixed $value) 列表左推入
 * @method static int rpush(string $key, mixed $value) 列表右推入
 * @method static mixed lpop(string $key) 列表左弹出
 * @method static mixed rpop(string $key) 列表右弹出
 * @method static int llen(string $key) 获取列表长度
 * @method static array lrange(string $key, int $start, int $stop) 获取列表范围
 * @method static int sadd(string $key, mixed $member) 集合添加成员
 * @method static int srem(string $key, mixed $member) 集合移除成员
 * @method static array smembers(string $key) 获取集合所有成员
 * @method static bool sismember(string $key, mixed $member) 检查成员是否在集合中
 * @method static int scard(string $key) 获取集合成员数量
 * @method static int hset(string $key, string $field, mixed $value) 哈希设置字段
 * @method static mixed hget(string $key, string $field) 哈希获取字段
 * @method static array hgetall(string $key) 获取哈希所有字段
 * @method static bool hdel(string $key, string $field) 哈希删除字段
 * @method static bool hexists(string $key, string $field) 检查哈希字段是否存在
 * @method static array hkeys(string $key) 获取哈希所有键
 * @method static array hvals(string $key) 获取哈希所有值
 * @method static int hlen(string $key) 获取哈希字段数量
 * @method static int zadd(string $key, float $score, mixed $member) 有序集合添加成员
 * @method static array zrange(string $key, int $start, int $stop, bool $withscores = false) 获取有序集合范围
 * @method static array zrevrange(string $key, int $start, int $stop, bool $withscores = false) 获取有序集合倒序范围
 * @method static int zcard(string $key) 获取有序集合成员数量
 * @method static float zscore(string $key, mixed $member) 获取有序集合成员分数
 * @method static int zrem(string $key, mixed $member) 有序集合移除成员
 * @method static bool select(int $db) 选择数据库
 * @method static bool flushdb() 清空当前数据库
 * @method static bool flushall() 清空所有数据库
 * @method static array info(string $section = null) 获取Redis信息
 * @method static int dbsize() 获取数据库键数量
 * @method static string ping() Ping服务器
 */
class Redis extends Facade
{
    /**
     * 获取门面对应的服务名称
     */
    protected static function getFacadeAccessor()
    {
        return 'redis';
    }
    
    /**
     * 选择Redis数据库
     * 当不传参数或传入null时，恢复到默认配置的数据库
     * 
     * @param int|null $db 数据库编号，null表示恢复到默认数据库
     * @return \Illuminate\Redis\Connections\PredisConnection|\Illuminate\Redis\Connections\Connection
     */
    public static function db(int $db = null)
    {
        $redisManager = static::resolveFacadeInstance('redis.manager');
        $connection = $redisManager->connection();
        
        if ($db === null) {
            // 获取配置中的默认数据库编号
            $config = static::getContainer()->get('config')['cache']['stores']['redis'];
            $db = $config['database'] ?? 0;
        }
        
        // 使用SELECT命令切换数据库
        $connection->select($db);
        
        return $connection;
    }
    
    /**
     * 恢复到默认配置的数据库
     * 
     * @return \Illuminate\Redis\Connections\Connection
     */
    public static function resetDb()
    {
        return static::db(null);
    }
    
    /**
     * 处理静态方法调用
     * 支持直接调用Redis方法，默认使用配置中的数据库
     */
    public static function __callStatic($method, $args)
    {
        // 如果调用了db方法，直接返回连接实例
        if ($method === 'db') {
            return static::db(...$args);
        }
        
        // 获取默认Redis连接（使用db()方法）
        $instance = static::db();
        
        if (! $instance) {
            throw new \Exception('Redis connection has not been set.');
        }
        
        return $instance->$method(...$args);
    }
}