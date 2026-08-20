<?php

namespace base\Orm;

use base\Database\Db;
use base\Database\Query\Query;

use function array_merge;
use function array_unique;
use function array_values;

/**
 * 多对多关系
 *
 * - 通过中间表关联两个模型
 * - 例: User belongsToMany Role (role_user 中间表, user_id/role_id)
 * - 因 DBAL 暂不支持关联限定通配/子查询, 采用两步查询: 中间表取关联键 → 相关表 IN 查询
 */
class BelongsToMany extends Relation {

    /**
     * 中间表名
     * @var string
     */
    protected string $pivotTable;

    /**
     * 中间表外键(指向父模型)
     * @var string
     */
    protected string $foreignPivotKey;

    /**
     * 中间表关联键(指向相关模型)
     * @var string
     */
    protected string $relatedPivotKey;

    /**
     * 父模型主键
     * @var string
     */
    protected string $parentKey;

    /**
     * 相关模型主键
     * @var string
     */
    protected string $relatedKey;

    /**
     * 是否已应用中间表过滤
     * @var bool
     */
    protected bool $pivotApplied=false;

    /**
     * 构造方法
     *
     * @access public
     * @param Db $db 数据库入口
     * @param string $relatedClass 关联模型类名
     * @param Model $parent 父模型
     * @param string $pivotTable 中间表名
     * @param string $foreignPivotKey 中间表外键(父侧)
     * @param string $relatedPivotKey 中间表关联键(相关侧)
     * @param string $parentKey 父模型主键
     * @param string $relatedKey 相关模型主键
     */
    public function __construct(
        Db $db,
        string $relatedClass,
        Model $parent,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey
    ) {
        $this->pivotTable=$pivotTable;
        $this->foreignPivotKey=$foreignPivotKey;
        $this->relatedPivotKey=$relatedPivotKey;
        $this->parentKey=$parentKey;
        $this->relatedKey=$relatedKey;
        parent::__construct($db,$relatedClass,$parent,$foreignPivotKey,$parentKey);
    }

    /**
     * 应用外键约束(多对多无简单约束, 由终端操作两步查询处理)
     *
     * @access protected
     * @return void
     */
    protected function applyConstraint(): void {
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
     * 查询集合(自动注入中间表过滤)
     *
     * @access public
     * @return ModelCollection
     */
    public function get(): ModelCollection {
        if(!$this->applyPivot())
            return new ModelCollection($this->modelClass);
        return parent::get();
    }

    /**
     * 查询单条(自动注入中间表过滤)
     *
     * @access public
     * @return Model|null
     */
    public function first(): ?Model {
        if(!$this->applyPivot())
            return null;
        return parent::first();
    }

    /**
     * 统计数量(自动注入中间表过滤)
     *
     * @access public
     * @return int
     */
    public function count(): int {
        if(!$this->applyPivot())
            return 0;
        return parent::count();
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
        $parentKeys=$this->parentKeyValues($parents,$this->parentKey);
        if(empty($parentKeys))
            return;
        // 第一步: 查中间表
        $pivotRows=$this->queryPivotRows($parentKeys);
        $map=array();
        foreach($pivotRows as $row)
            $map[$row[$this->foreignPivotKey]][]=$row[$this->relatedPivotKey];
        if(empty($map))
            return;
        // 展平所有关联键
        $flattened=array();
        foreach($map as $keys)
            $flattened=array_merge($flattened,$keys);
        $allKeys=array_values(array_unique($flattened));
        // 第二步: 查相关模型
        $builder=new ModelQueryBuilder($this->db,$this->modelClass);
        $builder->whereIn($this->relatedKey,$allKeys);
        $grouped=array();
        foreach($builder->get() as $model)
            $grouped[$model->getAttribute($this->relatedKey)][]=$model;
        // 按父模型分配
        foreach($parents as $parent) {
            $key=$parent->getAttribute($this->parentKey);
            if($key!==null&&isset($map[$key])) {
                $items=array();
                foreach($map[$key] as $relatedKey)
                    if(isset($grouped[$relatedKey]))
                        foreach($grouped[$relatedKey] as $model)
                            $items[]=$model;
                $parent->setRelation($name,new ModelCollection($this->modelClass,$items));
            }
        }
    }

    /**
     * 应用中间表过滤(将父模型的关联键注入到相关查询)
     *
     * - 返回 false 表示父模型无关联记录
     *
     * @access private
     * @return bool
     */
    private function applyPivot(): bool {
        if($this->pivotApplied)
            return true;
        $this->pivotApplied=true;
        $keys=$this->queryPivotKeys($this->parent->getAttribute($this->parentKey));
        if(empty($keys))
            return false;
        $this->whereIn($this->relatedKey,$keys);
        return true;
    }

    /**
     * 查询单个父模型的关联键列表
     *
     * @access private
     * @param mixed $parentKey 父模型主键值
     * @return array
     */
    private function queryPivotKeys(mixed $parentKey): array {
        $result=$this->db->query(
            Query::select()
                ->from($this->pivotTable)
                ->field($this->relatedPivotKey)
                ->where($this->foreignPivotKey,$parentKey)
        );
        $keys=array();
        foreach($result->getResults() as $row)
            $keys[]=$row[$this->relatedPivotKey];
        return $keys;
    }

    /**
     * 批量查询中间表行
     *
     * @access private
     * @param array $parentKeys 父模型主键值列表
     * @return array
     */
    private function queryPivotRows(array $parentKeys): array {
        $result=$this->db->query(
            Query::select()
                ->from($this->pivotTable)
                ->field(array($this->foreignPivotKey,$this->relatedPivotKey))
                ->whereIn($this->foreignPivotKey,$parentKeys)
        );
        return $result->getResults()->toArray();
    }

}
