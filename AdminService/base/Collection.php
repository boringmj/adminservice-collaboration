<?php

namespace base;

use \Countable;
use \ArrayIterator;
use \IteratorAggregate;

/**
 * 静态集合类 (一次性加载所有数据)
 * 
 * @package base
 * @template T of Model
 */
class Collection implements IteratorAggregate,Countable {

    /**
     * 保存数据集
     * 
     * @var T[]
     */
    protected array $data=[];

    /**
     * 构造函数
     * 
     * @param class-string<T> $model 模型类名
     * @param array $data 数据集
     */
    public function __construct(string $model,array $data=[]) {
        $this->data=$this->buildCollection($model,$data);
    }

    /**
     * 将数组集转为模型对象集
     * 
     * @param class-string<T> $model 模型对象
     * @param array $data 数据集
     * @return T[]
     */
    protected function buildCollection(string $model,array $data): array {
        $collection=[];
        foreach($data as $item) {
            $collection[]=$model::new($item);
        }
        return $collection;
    }

    /**
     * 获取迭代器
     * 
     * @return ArrayIterator<int,T>
     */
    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->data);
    }

    /**
     * 判断数据集是否为空
     * 
     * @return bool
     */
    public function isEmpty(): bool {
        return empty($this->data);
    }

    /**
     * 获取指定位置元素
     * 
     * @param int $index 索引位置
     * @param bool $to_array 是否转换为数组
     * @return T|array|null
     */
    public function get(int $index,bool $to_array=false): Model|array|null {
        if(!isset($this->data[$index]))
            return null;
        if($to_array)
            return $this->data[$index]->toArray();
        return $this->data[$index];
    }

    /**
     * 获取第一个元素
     * 
     * @return T|null
     */
    public function first(): Model|null {
        return $this->data[0]??null;
    }

    /**
     * 获取最后一个元素
     * 
     * @return T|null
     */
    public function last(): Model|null {
        return !empty($this->data)?$this->data[count($this->data)-1]:null;
    }

    /**
     * 返回所有数据
     * 
     * @param bool $to_array 是否转换为数组
     * @return array<T|array>
     */
    public function all(bool $to_array=false): array {
        if($to_array) {
            $result=[];
            foreach($this->data as $model)
                $result[]=$model->toArray();
            return $result;
        }
        return $this->data;
    }

    /**
     * 以数组的形式返回数据集
     * 
     * @return array
     */
    public function toArray(): array {
        return $this->all(true);
    }

    /**
     * 获取数据集数量
     * 
     * @return int
     */
    public function count(): int {
        return count($this->data);
    }

}