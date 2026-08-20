<?php

namespace base\Orm;

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
