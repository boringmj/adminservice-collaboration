<?php

namespace base;

class Collection implements \Iterator {

    /**
     * 保存数据集
     * 
     * @var Model[]
     */
    protected array $data=[];

    /**
     * 当前指针位置
     * 
     * @var int
     */
    protected int $index=0;

    /**
     * 构造函数
     * 
     * @param Model $model 模型对象
     * @param array $data 数据集
     */
    public function __construct(Model $model, array $data=[]) {
        $this->data=$this->buildCollection($model,$data);
    }

    /**
     * 将数组集转为模型对象集
     * 
     * @param Model $model 模型对象
     * @param array $data 数据集
     * @return Model[]
     */
    protected function buildCollection(Model $model,array $data): array {
        $collection=[];
        foreach($data as $item) {
            $collection[]=$model::new($item);
        }
        return $collection;
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
     * 获取当前指针位置
     * 
     * @return int
     */
    public function index(): int {
        return $this->index;
    }

    /**
     * 重置指针到起始位置
     * 
     * @return void
     */
    public function rewind(): void {
        $this->index=0;
    }

    /**
     * 检查当前指针位置是否有效
     * 
     * @return bool
     */
    public function valid(): bool {
        return $this->index<count($this->data);
    }

    /**
     * 获取当前元素
     * 
     * @return Model|null
     */
    public function current(): Model|null {
        return $this->get($this->index);
    }

    /**
     * 获取当前元素并将指针移动到下一个元素
     * 
     * @return Model|null
     */
    public function currentAndNext(): Model|null {
        $current=$this->current();
        $this->next();
        return $current;
    }

    /**
     * 获取当前键名
     * 
     * @return int
     */
    public function key(): int {
        return $this->index;
    }

    /**
     * 移动到下一个元素
     * 
     * @return void
     */
    public function next(): void {
        $this->index++;
    }

    /**
     * 获取指定位置元素
     * 
     * @param int $index 索引位置
     * @param bool $to_array 是否转换为数组
     * @return Model|array|null
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
     * @return Model|null
     */
    public function first(): Model|null {
        return $this->get(0);
    }

    /**
     * 获取最后一个元素
     * 
     * @return Model|null
     */
    public function last(): Model|null {
        $lastIndex=count($this->data)-1;
        return $this->get($lastIndex);
    }

    /**
     * 返回所有数据
     * 
     * @param bool $to_array 是否转换为数组
     * @return array
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
     * 以对象的形式返回数据集
     * 
     * @return array
     */
    public function toObject(): array {
        return $this->all(false);
    }

    /**
     * 获取数据集数量
     * 
     * @return int
     */
    public function count(): int {
        return count($this->data);
    }

    /**
     * 重置状态
     * 
     * @return void
     */
    public function reset(): void {
        $this->index=0;
    }

}