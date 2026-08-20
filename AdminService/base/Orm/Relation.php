<?php

namespace base\Orm;

use base\Database\Db;

use function array_unique;
use function array_values;

/**
 * 关系基类
 *
 * - 表示两个模型之间的关联(继承模型查询构建器, 自带外键约束)
 * - 可继续链式查询($relation->where(...)->get()), 也可惰性加载($model->relation)
 */
abstract class Relation extends ModelQueryBuilder {

    /**
     * 父模型(关联的拥有者)
     * @var Model
     */
    protected Model $parent;

    /**
     * 外键字段
     * @var string
     */
    protected string $foreignKey;

    /**
     * 主键字段(关联目标)
     * @var string
     */
    protected string $ownerKey;

    /**
     * 构造方法
     *
     * @access public
     * @param Db $db 数据库入口
     * @param string $relatedClass 关联模型类名
     * @param Model $parent 父模型
     * @param string $foreignKey 外键字段
     * @param string $ownerKey 主键字段
     */
    public function __construct(
        Db $db,
        string $relatedClass,
        Model $parent,
        string $foreignKey,
        string $ownerKey
    ) {
        parent::__construct($db,$relatedClass);
        $this->parent=$parent;
        $this->foreignKey=$foreignKey;
        $this->ownerKey=$ownerKey;
        $this->applyConstraint();
    }

    /**
     * 应用外键约束
     *
     * @access protected
     * @return void
     */
    abstract protected function applyConstraint(): void;

    /**
     * 获取关联结果(惰性加载)
     *
     * @access public
     * @return mixed
     */
    abstract public function getResults(): mixed;

    /**
     * 预加载关联到父模型集合
     *
     * @access public
     * @param string $name 关系名
     * @param ModelCollection $parents 父模型集合
     * @return void
     */
    abstract public function eagerLoad(string $name,ModelCollection $parents): void;

    /**
     * 收集父模型指定属性的值(去重)
     *
     * @access protected
     * @param ModelCollection $parents 父模型集合
     * @param string $attribute 属性名
     * @return array
     */
    protected function parentKeyValues(ModelCollection $parents,string $attribute): array {
        $keys=array();
        foreach($parents as $parent) {
            $key=$parent->getAttribute($attribute);
            if($key!==null)
                $keys[]=$key;
        }
        return array_values(array_unique($keys));
    }

}
