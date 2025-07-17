<?php

namespace base;

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
     * 是否以数组形式返回
     * 
     * @var bool
     */
    protected bool $return_array=false;

    /**
     * 构造函数
     * 
     * @param Model $model 模型对象
     * @param array $data 数据集
     */
    public function __construct(Model $model,array $data=[]) {
        $this->model=$model;
        $this->data=$this->BuildCollection($data);
    }

    /**
     * 将数组集转为模型对象集
     * 
     * @param array $data 数据集
     * @return array
     */
    protected function BuildCollection(array $data=[]): array {
        // 防止奇怪的键名,直接使用新数组
        $temp=[];
        foreach ($data as $value)
            $temp[]=$this->model::new($value);
        return $data;
    }

    /**
     * 获取当前索引
     * 
     * @return int
     */
    public function index(): int {
        return $this->index;
    }

    /**
     * 重置索引
     * 
     * @return void
     */
    public function rewind(): void {
        $this->index=0;
    }

    /**
     * 校验索引是否合法
     * 
     * @param int $index 索引
     * @return bool
     */
    public function valid(int $index=0): bool {
        return isset($this->data[$index]);
    }

    /**
     * 获取指定索引位置的数据
     * 
     * @param int $index 索引
     * @return Model|array|null
     */
    public function get(int $index=0): Model|array|null {
        if($this->valid($index))
            if($this->return_array)
                return $this->data[$index]->toArray();
            else
                return $this->data[$index];
        return null;
    }

    /**
     * 获取当前索引位置的数据
     * 
     * @return Model|array|null
     */
    public function current(): Model|array|null {
        return $this->get($this->index);
    }

    /**
     * 获取下一个索引位置的数据(索引向后移动一位)
     * 
     * @return Model|array|null
     */
    public function next(): Model|array|null {
        if($this->valid($this->index+1))
            return $this->get($this->index++);
        return null;
    }

    /**
     * 获取上一个索引位置的数据(索引向前移动一位)
     * 
     * @return Model|array|null
     */
    public function prev(): Model|array|null {
        if($this->valid($this->index-1))
            return $this->get($this->index--);
        return null;
    }

    /**
     * 重置索引并返回当前索引位置的数据
     * 
     * @return Model|null
     */
    public function first(): Model|null {
        $this->rewind();
        return $this->current();
    }

    /**
     * 重置索引并返回最后一个索引位置的数据
     * 
     * @return Model|array|null
     */
    public function last(): Model|array|null {
        $this->rewind();
        $this->next();
        return $this->current();
    }

    /**
     * 返回全部数据集
     * 
     * @return array
     */
    public function all(): array {
        if($this->return_array) {
            $temp=[];
            foreach ($this->data as $value)
                $temp[]=$value->toArray();
            return $temp;
        }
        return $this->data;
    }

    /**
     * 以数组形式返回
     * 
     * @return static
     */
    public function toArray(): static {
        $this->return_array=true;
        return $this;
    }

}