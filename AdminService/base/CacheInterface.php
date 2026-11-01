<?php

namespace base;

/**
 * 通用缓存契约
 *
 * - 面向用户业务代码与框架运行期动态数据, 与系统编译缓存(`runtime/cache/compile`)分离
 * - 单键独立过期、版本指纹、原子计数与分池隔离
 * - 具体实现见 `AdminService\Cache`; 未命中 / 已过期 / 文件损坏时统一返回默认值
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface CacheInterface {

    /**
     * 读取缓存
     *
     * - 键不存在、已过期或缓存文件损坏时返回默认值
     * - 显式缓存 `null` 时仍返回 `null`, 不会回落默认值
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed;

    /**
     * 写入缓存
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $value 缓存值(对象会序列化存储)
     * @param int|null $ttl 过期秒数; null = 使用默认 TTL, <=0 = 永不过期
     * @param string|null $version 版本指纹; 读取时可选校验
     * @return bool
     */
    public function set(string $key,mixed $value,?int $ttl=null,?string $version=null): bool;

    /**
     * 写入永不过期缓存
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param string|null $version 版本指纹
     * @return bool
     */
    public function forever(string $key,mixed $value,?string $version=null): bool;

    /**
     * 判断缓存是否存在且未过期
     *
     * @access public
     * @param string $key 缓存键
     * @param string|null $version 版本指纹; null = 不校验
     * @return bool
     */
    public function has(string $key,?string $version=null): bool;

    /**
     * 命中则返回缓存; 未命中或版本不符时执行回调并写入
     *
     * @access public
     * @param string $key 缓存键
     * @param int|null $ttl 过期秒数; null = 使用默认 TTL, <=0 = 永不过期
     * @param callable $callback 回源回调(无参数)
     * @param string|null $version 版本指纹
     * @return mixed
     * @throws \AdminService\Exception 回调后的值无法缓存
     */
    public function remember(string $key,?int $ttl,callable $callback,?string $version=null): mixed;

    /**
     * 读取并删除缓存
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function pull(string $key,mixed $default=null): mixed;

    /**
     * 原子自增
     *
     * @access public
     * @param string $key 缓存键
     * @param int $step 步长
     * @param int|null $ttl 键不存在时的过期秒数; 已存在时保留原过期时间
     * @return int
     * @throws \AdminService\Exception 缓存值不是整数
     */
    public function increment(string $key,int $step=1,?int $ttl=null): int;

    /**
     * 原子自减
     *
     * @access public
     * @param string $key 缓存键
     * @param int $step 步长
     * @param int|null $ttl 键不存在时的过期秒数; 已存在时保留原过期时间
     * @return int
     * @throws \AdminService\Exception 缓存值不是整数
     */
    public function decrement(string $key,int $step=1,?int $ttl=null): int;

    /**
     * 删除缓存
     *
     * @access public
     * @param string $key 缓存键
     * @return bool 缓存已不存在时返回 true(幂等)
     */
    public function delete(string $key): bool;

    /**
     * 批量读取
     *
     * @access public
     * @param iterable<string> $keys 键列表
     * @param mixed $default 默认值
     * @return array<string,mixed>
     */
    public function getMultiple(iterable $keys,mixed $default=null): array;

    /**
     * 批量写入
     *
     * @access public
     * @param iterable<string,mixed> $values 键值表
     * @param int|null $ttl 过期秒数
     * @return bool
     */
    public function setMultiple(iterable $values,?int $ttl=null): bool;

    /**
     * 批量删除
     *
     * @access public
     * @param iterable<string> $keys 键列表
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool;

    /**
     * 清空当前缓存池
     *
     * - 只清当前 `scope()` 指定的池, 不影响其它池与系统编译缓存
     *
     * @access public
     * @return bool
     */
    public function clear(): bool;

    /**
     * 派生到另一个缓存池
     *
     * @access public
     * @param string $pool 缓存池名(只允许字母、数字、下划线、短横线)
     * @return static
     */
    public function scope(string $pool): static;

    /**
     * 触发一次增量垃圾回收
     *
     * @access public
     * @param int|null $max_files 本次最多检查文件数; null = 使用配置, <=0 = 不限制
     * @return int 本次清理的文件数
     */
    public function gc(?int $max_files=null): int;

}
