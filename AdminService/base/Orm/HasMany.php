<?php

namespace base\Orm;

/**
 * 一对多关系
 *
 * - 父模型一条记录对应关联模型多条记录
 * - 例: User hasMany Post (posts.user_id = users.id)
 */
class HasMany extends Relation {

    /**
     * 应用外键约束
     *
     * @access protected
     * @return void
     */
    protected function applyConstraint(): void {
        $this->where($this->foreignKey,$this->parent->getAttribute($this->ownerKey));
    }

    /**
     * 获取关联结果(模型集合)
     *
     * @access public
     * @return ModelCollection
     */
    public function getResults(): ModelCollection {
        return $this->get();
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
            $parent->setRelation($name,new ModelCollection($this->modelClass));
        $keys=$this->parentKeyValues($parents,$this->ownerKey);
        if(empty($keys))
            return;
        $builder=new ModelQueryBuilder($this->db,$this->modelClass);
        $builder->whereIn($this->foreignKey,$keys);
        $grouped=array();
        foreach($builder->get() as $model)
            $grouped[$model->getAttribute($this->foreignKey)][]=$model;
        foreach($parents as $parent) {
            $key=$parent->getAttribute($this->ownerKey);
            if($key!==null&&isset($grouped[$key]))
                $parent->setRelation($name,new ModelCollection($this->modelClass,$grouped[$key]));
        }
    }

}
