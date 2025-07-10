<?php

namespace base;

use AdminService\Exception;

class Collection {

    /**
     * 保存数据集
     * 
     * @var array
     */
    protected array $data=[];

    /**
     * 模型对象
     * 
     * @var Model
     */
    protected Model $model;

    /**
     * 当前索引

     * @var int
     */
    protected int $index=0;

    /**
     * 构造函数
     * 
     * @param Model $model 模型对象
     * @param array $data 数据集
     */
    public function __construct(Model $model,array $data=[]) {
        $this->model=$model;
        $this->data=$data;
    }

    /**
     * 获取全部数据集
     * 
     * @return array
     */
    public function getData(): array {
        return $this->data;
    }

    /**
     * 获取数据集长度
     * 
     * @return int
     */
    public function length(): int {
        return count($this->data);
    }

    /**
     * 获取数据集的第一个元素
     * 
     * @return mixed
     */
    public function first(): Model {
        return $this->model->new($this->data[0]);
    }

    /**
     * 获取数据集的最后一个元素
     * 
     * @return mixed
     */
    public function last(): mixed {
        return $this->model->new($this->data[count($this->data)-1]);
    }

    /**
     * 获取数据集的指定元素
     * 
     * @param int $index 索引
     * @return mixed
     */
    public function get(int $index): mixed {
        if($index<0||$index>=count($this->data))
            throw new Exception('Index out of range');
        return $this->model->new($this->data[$index]);
    }

    /**
     * 判断索引是否有效
     * 
     * @return bool
     */
    public function valid(): bool {
        if($this->index<count($this->data))
            return true;
        return false;
    }

    /**
     * 重置索引
     * 
     * @return void
     */
    public function reset(): void {
        $this->index=0;
    }

    /**
     * 获取下一个索引位置
     * 
     * @return int
     */
    public function nextIndex(): int {
        $index=$this->index+1;
        if($this->valid())
            return $index;
        return -1;
    }

    /**
     * 获取当前索引位置
     * 
     * @return int
     */
    public function index(): int {
        return $this->index;
    }

    /**
     * 设置索引位置
     * 
     * @param int $index 索引
     * @return void
     */
    public function setIndex(int $index): void {
        if($index>=0&&$index<count($this->data))
            $this->index=$index;
        else
            throw new Exception('索引超出范围');
    }

    /**
     * 获取当前索引位置的数据
     * 
     * @return mixed
     */
    public function current(): mixed {
        return $this->data[$this->index];
    }

    /**
     * 获取当前索引位置且将索引位置向后移动一位
     * 
     * @return mixed
     */
    public function next(): mixed {
        $this->index++;
        if($this->valid())
            return $this->data[$this->index];
        return null;
    }

    /**
     * 以数组形式返回所以数据
     */
    public function toArray(): array {
        return $this->data;
    }

    /**
     * 以数组形式返回当前索引位置的数据
     */
    public function toCurrentArray(): array {
        return [$this->data[$this->index]];
    }

    /**
     * 以数组形式返回当前索引位置的数据并向后移动一位
     */
    public function toNextArray(): array {
        $this->index++;
        if($this->valid())
            return [$this->data[$this->index]];
        return [];
    }

}