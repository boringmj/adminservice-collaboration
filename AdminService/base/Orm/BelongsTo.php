<?php

namespace base\Orm;

use base\Orm\Exception\OrmException;

/**
 * 多对一关系(属于)
 *
 * - 父模型一条记录属于关联模型一条记录
 * - 例: Post belongsTo User (posts.user_id = users.id)
 */
class BelongsTo extends Relation {

    /**
     * 应用外键约束
     *
     * @access protected
     * @return void
     */
    protected function applyConstraint(): void {
        $this->where($this->ownerKey,$this->parent->getAttribute($this->foreignKey));
    }

    /**
     * 通过关系创建关联记录
     *
     * - belongsTo 关系不支持 create(): 外键在子模型一侧, 语义应由子模型直接创建
     *
     * @access public
     * @param array $data 数据
     * @return Model
     * @throws OrmException 始终抛出
     */
    public function create(array $data): Model {
        throw new OrmException('Cannot create via belongsTo relation: foreign key lives on the child.',100722);
    }

    /**
     * 获取关联结果(单个模型)
     *
     * @access public
     * @return Model|null
     */
    public function getResults(): ?Model {
        return $this->first();
    }

    /**
     * 预加载关联到父模型集合
     *
     * @access public
     * @param string $name 关系名
     * @param ModelCollection $parents 父模型集合
     * @return void
     */
    public function eagerLoad(string $name,ModelCollection $parents): void {
        foreach($parents as $parent)
            $parent->setRelation($name,null);
        $keys=$this->parentKeyValues($parents,$this->foreignKey);
        if(empty($keys))
            return;
        $builder=new ModelQueryBuilder($this->db,$this->modelClass);
        $builder->whereIn($this->ownerKey,$keys);
        $grouped=array();
        foreach($builder->get() as $model)
            $grouped[$model->getAttribute($this->ownerKey)][]=$model;
        foreach($parents as $parent) {
            $key=$parent->getAttribute($this->foreignKey);
            if($key!==null&&isset($grouped[$key]))
                $parent->setRelation($name,$grouped[$key][0]);
        }
    }

}
