<?php

namespace base\Orm;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;

/**
 * 模型集合
 *
 * - 承载查询返回的模型实例, 可迭代、可计数
 */
class ModelCollection implements Countable, IteratorAggregate {

    /**
     * 模型类名
     * @var class-string<Model>
     */
    protected string $modelClass;

    /**
     * 模型实例列表
     * @var Model[]
     */
    protected array $models;

    /**
     * 构造方法
     *
     * @access public
     * @param string $modelClass 模型类名
     * @param array<Model> $models 模型实例列表
     */
    public function __construct(string $modelClass,array $models=array()) {
        $this->modelClass=$modelClass;
        $this->models=$models;
    }

    /**
     * 获取模型类名
     *
     * @access public
     * @return string
     */
    public function getModelClass(): string {
        return $this->modelClass;
    }

    /**
     * 获取全部模型实例
     *
     * @access public
     * @return Model[]
     */
    public function all(): array {
        return $this->models;
    }

    /**
     * 获取第一个模型
     *
     * @access public
     * @return Model|null
     */
    public function first(): ?Model {
        foreach($this->models as $model)
            return $model;
        return null;
    }

    /**
     * 获取最后一个模型
     *
     * - 按值取尾, 与键无关(过滤后的集合键可能不连续)
     *
     * @access public
     * @return Model|null
     */
    public function last(): ?Model {
        $last=null;
        foreach($this->models as $model)
            $last=$model;
        return $last;
    }

    /**
     * 获取指定位置的模型
     *
     * @access public
     * @param int $index 索引
     * @return Model|null
     */
    public function get(int $index): ?Model {
        return $this->models[$index]??null;
    }

    /**
     * 获取数量
     *
     * @access public
     * @return int
     */
    public function count(): int {
        return count($this->models);
    }

    /**
     * 是否为空
     *
     * @access public
     * @return bool
     */
    public function isEmpty(): bool {
        return empty($this->models);
    }

    /**
     * 获取某字段的值列表
     *
     * @access public
     * @param string $field 字段名
     * @return array
     */
    public function pluck(string $field): array {
        return array_map(function($model) use ($field) {
            return $model->getAttribute($field);
        },$this->models);
    }

    /**
     * 遍历集合中的每个模型
     *
     * - 按顺序将每个模型实例交给回调, 常用于逐行处理副作用(写日志/清缓存/逐条保存)
     * - 回调的返回值被忽略; 方法返回 $this 以便链式调用
     *
     * 回调签名:
     * ```
     * function(Model $model, int|string $key): void
     * ```
     * - $model: 当前模型实例
     * - $key:   当前模型在集合中的键(查询返回的集合键为 0,1,2...; 过滤后的集合键可能不连续)
     *
     * @access public
     * @param callable(Model, int|string): void $callback 遍历回调
     * @return static 返回自身, 支持链式
     */
    public function each(callable $callback): static {
        foreach($this->models as $key=>$model)
            $callback($model,$key);
        return $this;
    }

    /**
     * 映射集合生成新集合
     *
     * - 将每个模型交给回调, 用回调的返回值构建一个新集合, 原集合不被修改
     * - 回调返回值可以是任意类型(模型/标量/数组), 常用于取字段或转换数据形态
     *
     * 回调签名:
     * ```
     * function(Model $model, int|string $key): mixed
     * ```
     * - $model: 当前模型实例
     * - $key:   当前模型在集合中的键
     * - 返回值: 新集合中该键位置存放的值
     *
     * @access public
     * @param callable(Model, int|string): mixed $callback 映射回调
     * @return static 包含映射结果的新集合(键与原集合一致)
     */
    public function map(callable $callback): static {
        $mapped=array();
        foreach($this->models as $key=>$model)
            $mapped[$key]=$callback($model,$key);
        return new static($this->modelClass,$mapped);
    }

    /**
     * 过滤集合生成新集合
     *
     * - 仅保留回调返回真值(truthy)的模型, 返回新集合, 原集合不被修改
     * - 保留原键: 过滤后的集合键可能不连续(如保留 1,3), first()/last() 按值取不受影响
     *
     * 回调签名:
     * ```
     * function(Model $model, int|string $key): bool
     * ```
     * - $model: 当前模型实例
     * - $key:   当前模型在集合中的键
     * - 返回值: true 保留该模型, false/假值丢弃
     *
     * @access public
     * @param callable(Model, int|string): bool $callback 过滤回调
     * @return static 过滤后的新集合(键与原集合一致)
     */
    public function filter(callable $callback): static {
        $kept=array();
        foreach($this->models as $key=>$model) {
            if($callback($model,$key))
                $kept[$key]=$model;
        }
        return new static($this->modelClass,$kept);
    }

    /**
     * 批量更新集合成员(单条 SQL, 按主键 whereIn)
     *
     * - 自动刷新 updated_at(时间戳模型), 已显式传入则不覆盖
     * - 跳过缺失主键的实例; 空集合为 no-op 不发起查询
     * - 与 Model::where(...)->update() 一致, 不逐行做 fillable 校验
     * - 注意: 软删模型会自动附加 "deleted_at IS NULL" 过滤, 集合中被软删的行会被跳过
     *   (不报错, 只是不生效); 需要操作已软删行请用 forceDelete() 或先 restore()
     *
     * @access public
     * @param array<string,mixed> $data 数据
     * @return int 受影响行数
     */
    public function update(array $data): int {
        $keys=$this->modelKeys();
        if(empty($keys))
            return 0;
        $modelClass=$this->modelClass;
        if($modelClass::usesTimestamps()&&!array_key_exists($modelClass::updatedAtField(),$data))
            $data[$modelClass::updatedAtField()]=$modelClass::freshTimestamp();
        return $modelClass::query()
            ->whereIn($modelClass::primaryKey(),$keys)
            ->update($data);
    }

    /**
     * 批量删除集合成员(单条 SQL, 按主键 whereIn)
     *
     * - 软删模型走软删标记, 否则物理删除
     * - 跳过缺失主键的实例; 空集合为 no-op 不发起查询
     * - 注意: 软删模型会自动附加 "deleted_at IS NULL" 过滤, 集合中已软删的行不会被重复标记;
     *   需要物理清除(含已软删行)请用 forceDelete()
     *
     * @access public
     * @return int 受影响行数
     */
    public function delete(): int {
        $keys=$this->modelKeys();
        if(empty($keys))
            return 0;
        $modelClass=$this->modelClass;
        return $modelClass::query()
            ->whereIn($modelClass::primaryKey(),$keys)
            ->delete();
    }

    /**
     * 批量物理删除集合成员(无视软删除, 单条 SQL, 按主键 whereIn)
     *
     * - 与 delete() 不同, 这里按主键精确操作, 集合中被软删的行也会被物理删除
     * - 常用于清除 onlyTrashed()/withTrashed() 集合中的已软删行
     * - 跳过缺失主键的实例; 空集合为 no-op 不发起查询
     *
     * @access public
     * @return int 受影响行数
     */
    public function forceDelete(): int {
        $keys=$this->modelKeys();
        if(empty($keys))
            return 0;
        $modelClass=$this->modelClass;
        return $modelClass::query()
            ->withTrashed()
            ->whereIn($modelClass::primaryKey(),$keys)
            ->forceDelete();
    }

    /**
     * 收集集合成员的主键值(过滤缺失主键的实例)
     *
     * @access private
     * @return array<mixed>
     */
    private function modelKeys(): array {
        $keys=array();
        foreach($this->models as $model)
            $keys[]=$model->getKey();
        return array_values(array_filter($keys,function($key) {
            return $key!==null;
        }));
    }

    /**
     * 转为数组(模型属性数组列表)
     *
     * @access public
     * @return array
     */
    public function toArray(): array {
        return array_map(function($model) {
            return $model->toArray();
        },$this->models);
    }

    /**
     * 获取迭代器
     *
     * @access public
     * @return Traversable
     */
    public function getIterator(): Traversable {
        return new ArrayIterator($this->models);
    }

}
