<?php

namespace base;

use \Generator;
use \IteratorAggregate;

/**
 * 一次性游标集合类
 * 
 * @package base
 * @template T of Model
 */
class CursorCollection implements IteratorAggregate {

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
     * 游标是否已耗尽
     * 
     * @var bool
     */
    protected bool $exhausted=false;

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
    }

    /**
     * 获取生成器
     * 
     * @return Generator<int,T>
     */
    public function getIterator(): Generator {
        while($row=$this->fetch()) {
            yield $row;
        }
    }

    /**
     * 获取下一条数据
     * 
     * @return T|null
     */
    public function fetch(): Model|null {
        if($this->exhausted) return null;
        if(!$this->source->valid()) {
            $this->exhausted=true;
            return null;
        }
        $row=$this->model::new($this->source->current(),false);
        $this->source->next();
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