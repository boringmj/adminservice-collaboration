<?php

namespace base;

use \Iterator;
use \Generator;
use \LogicException;

/**
 * 游标集合类
 * 
 * @package base
 * @template T of Model
 */
class CursorCollection implements Iterator {

    /**
     * 模型对象
     * 
     * @var class-string<T>
     */
    protected string $model;

    /**
     * 数据源 (数组或生成器)
     * 
     * @var Generator
     */
    protected Generator $source;

    /**
     * 当前元素
     * 
     * @var T|null
     */
    protected ?Model $current=null;

    /**
     * 当前键名
     * 
     * @var int
     */
    protected int $key=0;

    /**
     * 构造函数
     * 
     * @param class-string<T> $model 模型类名
     * @param array|Generator $source 数据源
     */
    public function __construct(string $model,array|Generator $source) {
        $this->model=$model;
        // 如果是数组则包装成生成器
        if($source instanceof Generator) {
            $this->source=$source;
        } else {
            $this->source=(function() use($source) {
                foreach($source as $row) {
                    yield $row;
                }
            })();
        }
        // 初始化游标
        $this->next();
    }

    /**
     * 获取当前元素
     * 
     * @return T|null
     */
    public function current(): Model|null {
        return $this->current;
    }

    /**
     * 获取当前键名
     * 
     * @return int
     */
    public function key(): int {
        return $this->key;
    }

    /**
     * 移动到下一个元素
     * 
     * @return void
     */
    public function next(): void {
        if($this->source->valid()) {
            $this->current=$this->model::new($this->source->current());
            $this->source->next();
            $this->key++;
        } else {
            $this->current=null;
        }
    }

    /**
     * 重置指针 (游标集合不支持)
     * 
     * @throws LogicException
     * @return void
     */
    public function rewind(): void {
        throw new LogicException("CursorCollection cannot be rewound");
    }

    /**
     * 检查当前指针位置是否有效
     * 
     * @return bool
     */
    public function valid(): bool {
        return $this->current!==null;
    }

    /**
     * 获取下一条数据
     * 
     * @return T|null
     */
    public function fetch(): Model|null {
        if(!$this->valid()) return null;
        $row=$this->current;
        $this->next();
        return $row;
    }

    /**
     * 获取所有数据
     * 
     * @return T[]
     */
    public function fetchAll(): array {
        $result=[];
        while($row=$this->fetch()) {
            $result[]=$row;
        }
        return $result;
    }

}