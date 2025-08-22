<?php

namespace AdminService;

use \Countable;
use \ArrayIterator;
use \IteratorAggregate;

/**
 * 数据类
 * 
 * 用于处理数据。
 */
class Data implements IteratorAggregate,Countable {

    /**
     * 键名是否大小写敏感
     * @var bool
     */
    protected $caseSensitive=true;

    /**
     * 存储的数据
     * @var array
     */
    protected $data=array();

    /**
     * 构造函数
     * 
     * @access public
     * @param array $data 数据
     * @return void
     */
    public function __construct(array $data=[]) {
        $this->init($data);
    }

    /**
     * 设置是否大小写敏感
     * 
     * @access public
     * @param bool $caseSensitive 是否大小写敏感
     * @return self
     */
    public function setCaseSensitive(bool $caseSensitive): self {
        $this->caseSensitive=$caseSensitive;
        return $this;
    }

    /**
     * 转换键名
     * 
     * @param string $key 键名
     * @return string
     */
    protected function convertKey(string $key): string {
        return $this->caseSensitive?$key:strtolower($key);
    }

    /**
     * 重置键名(谨慎,不可逆向操作,没有缓存会造成性能损失)
     * 
     * @return self
     */
    public function resetKey(): self {
        // 判断是否需要转换键名
        if($this->caseSensitive)
            return $this;
        // 转换键名
        $data=[];
        foreach($this->data as $key=>$val)
            $data[$this->convertKey($key)]=$val;
        $this->data=$data;
        return $this;
    }

    /**
     * 初始化
     * 
     * @access public
     * @param array $data 数据
     * @return void
     */
    public function init(array $data): void {
        $this->data=$data;
        $this->caseSensitive=true;
    }

    /**
     * 存储数据
     * 
     * @access public
     * @param array $data 数据
     * @return void
     */
    public function save(array $data): void {
        $this->data=array_merge($this->data,$data);
    }

    /**
     * 获取全部数据
     * 
     * @access public
     * @return array
     */
    public function all(): array {
        return $this->data;
    }

    /**
     * 获取指定键名的数据
     * 
     * @access public
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
        return $this->data[$this->convertKey($key)]??$default;
    }

    /**
     * 通过指定键名设置数据
     * 
     * @access public
     * @param string $key 键名
     * @param mixed $value 值
     * @return void
     */
    public function set(string $key,mixed $value): void {
        $this->data[$this->convertKey($key)]=$value;
    }

    /**
     * 判断指定键名的数据是否存在
     * 
     * @access public
     * @param string $key 键名
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->data[$this->convertKey($key)]);
    }

    /**
     * 删除指定键名的数据
     * 
     * @access public
     * @param string $key 键名
     * @return void
     */
    public function delete(string $key): void {
        unset($this->data[$this->convertKey($key)]);
    }

    /**
     * 获取迭代器
     * 
     * @access public
     * @return ArrayIterator<string, mixed>
     */
    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->data);
    }

    /**
     * 统计数据
     * 
     * @access public
     * @return int
     */
    public function count(): int {
        return count($this->data);
    }

    /**
     * 获取键名
     * 
     * @access public
     * @return array
     */
    public function keys(): array {
        return array_keys($this->data);
    }

    /**
     * 批量设置数据
     * 
     * @access public
     * @param array $data 数据
     * @return void
     */
    public function batchSet(array $data): void {
        foreach($data as $key=>$val)
            $this->set($this->convertKey($key),$val);
    }
    
}