<?php

namespace base\Orm;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function array_map;
use function count;

/**
 * 模型集合
 *
 * - 承载查询返回的模型实例, 可迭代、可计数
 */
class ModelCollection implements Countable, IteratorAggregate {

    /**
     * 模型类名
     * @var string
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
     * @param array $models 模型实例列表
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
        return $this->models[0]??null;
    }

    /**
     * 获取最后一个模型
     *
     * @access public
     * @return Model|null
     */
    public function last(): ?Model {
        return !empty($this->models)?$this->models[count($this->models)-1]:null;
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
