<?php

namespace base\Orm;

use base\Database\Db;
use base\Database\Sql\Definition\Where;
use base\Orm\Exception\OrmException;

use function array_unique;
use function array_values;
use function count;

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
     * 键约束条件节点(仅构造期 applyConstraint 追加; 预加载批量查询时剔除并替换为键集合)
     * @var Where|null
     */
    protected ?Where $constraint=null;

    /**
     * 构造方法
     *
     * @access public
     * @param Db $db 数据库入口
     * @param class-string<Model> $relatedClass 关联模型类名
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
     * 追加关系键约束并记录约束节点
     *
     * - 仅限构造期 applyConstraint 调用; 预加载批量查询时按节点同一性剔除
     *
     * @access protected
     * @param string $field 键字段
     * @param mixed $value 键值(null 编译为 IS NULL)
     * @return void
     */
    protected function constrainKey(string $field,mixed $value): void {
        $count=count($this->query->getWheres());
        $this->query->where($field,$value);
        // where() 恰好追加一个条件节点, 新增末位即键约束
        $wheres=$this->query->getWheres();
        $this->constraint=$wheres[$count]??null;
    }

    /**
     * 构建批量预加载构建器(保留关系方法链上的查询条件)
     *
     * - 保留字段/去重/关联/条件/排序/分组/锁/别名/软删开关与嵌套 with();
     *   键约束替换为键集合 IN; 字段列表缺键字段时补入(归位按该字段)
     * - 关系查询设置了 limit/offset 时抛出: 批量查询无法表达按父模型的数量限制
     *
     * @access protected
     * @param string $name 关系名
     * @param string $keyField 键字段
     * @param array<mixed> $keys 键值列表
     * @return ModelQueryBuilder
     * @throws OrmException 关系查询设置了 limit/offset
     */
    protected function newEagerBuilder(string $name,string $keyField,array $keys): ModelQueryBuilder {
        if($this->query->getLimit()!==null||$this->query->getOffset()!==null)
            throw new OrmException(
                'Cannot eager load relation "'.$name.'": limit/offset on relation cannot be expressed in batch preloading.',
                100741,
                array('relation'=>$name,'model'=>$this->modelClass)
            );
        $builder=new ModelQueryBuilder($this->db,$this->modelClass);
        if($this->alias!==null)
            $builder->alias($this->alias);
        if($this->withTrashed)
            $builder->withTrashed();
        if($this->onlyTrashed)
            $builder->onlyTrashed();
        if(!empty($this->eagerRelations))
            $builder->with($this->eagerRelations);
        $source=$this->query;
        $query=$builder->getQuery();
        if($source->isDistinct())
            $query->distinct();
        $hasKeyField=false;
        $columns=$source->getColumns();
        foreach($columns as $column) {
            $query->field($column[0],$column[1]);
            if($column[1]===null&&(string)$column[0]===$keyField)
                $hasKeyField=true;
        }
        // 调用方限制了字段且未含键字段时补入(归位按该字段); 未限制时为 SELECT *
        if(!empty($columns)&&!$hasKeyField)
            $query->field($keyField);
        foreach($source->getJoins() as $join)
            $query->join($join->getType(),$join->getTable(),$join->getConditions());
        foreach($source->getOrder() as $order)
            $query->order($order[0],$order[1]);
        foreach($source->getGroup() as $field)
            $query->group($field);
        foreach($source->getWheres() as $where)
            if($where!==$this->constraint)
                $query->addWhere($where);
        if($source->getLock()!==null)
            $query->lock($source->getLock());
        $builder->whereIn($keyField,$keys);
        return $builder;
    }

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
     * 保存关系模型，失败时抛异常
     *
     * @access protected
     * @param Model $model 待保存模型
     * @return void
     * @throws OrmException 写入失败
     */
    protected function saveRelated(Model $model): void {
        if(!$model->save())
            throw new OrmException('Related record create failed.',100733,array(
                'model'=>$this->modelClass
            ));
    }

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
