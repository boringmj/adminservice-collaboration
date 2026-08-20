<?php

namespace base\Orm;

use base\Database\Db;
use base\Database\Query\Query;
use base\Orm\Exception\OrmException;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function range;

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
     * 通过关系创建关联记录(并写入中间表关联)
     *
     * - 创建相关模型后自动插入中间表行, 使关联生效
     * - 例: $user->roles()->create(['name'=>'admin']) → 新角色 + role_user(user_id, role_id)
     *
     * @access public
     * @param array $data 数据
     * @return Model 已保存的关联模型
     * @throws OrmException 父模型未持久化
     */
    public function create(array $data): Model {
        $parentKey=$this->parent->getAttribute($this->parentKey);
        if($parentKey===null)
            throw new OrmException('Cannot create related record: parent is not persisted.',100723);
        // 先建相关模型, 再写中间表(两步, 非原子; 需要原子性时请在同一 Db 上包裹事务)
        $model=new ($this->modelClass)();
        $model->fill($data);
        $model->save();
        $this->db->query(
            Query::insert(array(
                $this->foreignPivotKey=>$parentKey,
                $this->relatedPivotKey=>$model->getAttribute($this->relatedKey),
            ))->from($this->pivotTable)
        );
        return $model;
    }

    /**
     * 批量更新(不支持)
     *
     * - 多对多只反映父模型的关联集合, 更新语义属于相关模型本身;
     *   关联的增删应通过中间表(attach/detach)处理
     *
     * @access public
     * @param array $data 数据
     * @return int
     * @throws OrmException 始终抛出
     */
    public function update(array $data): int {
        throw new OrmException(
            'Cannot bulk update through belongsToMany relation: association changes belong to the pivot table. '
            .'Query the related model directly instead.',
            100724
        );
    }

    /**
     * 批量删除(不支持)
     *
     * - 相关记录可能被多个父模型共享, 直接删除会留下悬空中间表行
     *
     * @access public
     * @return int
     * @throws OrmException 始终抛出
     */
    public function delete(): int {
        throw new OrmException(
            'Cannot delete through belongsToMany relation: related records may be shared by other parents. '
            .'Manage associations via the pivot table (attach/detach) instead.',
            100725
        );
    }

    /**
     * 批量物理删除(不支持)
     *
     * @access public
     * @return int
     * @throws OrmException 始终抛出
     */
    public function forceDelete(): int {
        throw new OrmException(
            'Cannot force delete through belongsToMany relation: related records may be shared by other parents.',
            100726
        );
    }

    /**
     * 关联相关模型(写入中间表)
     *
     * - $ids 支持三种写法:
     *   - 单个主键: attach(1)
     *   - 主键数组: attach([1, 2])
     *   - [主键 => 中间表附加字段]: attach([1=>['level'=>'owner'], 2=>['level'=>'member']])
     *
     * @access public
     * @param mixed $ids 相关模型主键(或 [主键=>附加字段] 映射)
     * @return void
     * @throws OrmException 父模型未持久化
     */
    public function attach(mixed $ids): void {
        $parentKey=$this->parent->getAttribute($this->parentKey);
        if($parentKey===null)
            throw new OrmException('Cannot attach related records: parent is not persisted.',100730);
        $rows=$this->buildPivotRows($parentKey,$ids);
        if(empty($rows))
            return;
        $this->db->query(
            Query::insert($this->padPivotRows($rows))->from($this->pivotTable)
        );
    }

    /**
     * 取消关联(删除中间表行)
     *
     * - 不传 $ids 时解除全部关联
     * - 例: detach() / detach(1) / detach([1, 2])
     *
     * @access public
     * @param mixed $ids 相关模型主键(或主键数组), 为空则全部
     * @return int 受影响行数
     * @throws OrmException 父模型未持久化
     */
    public function detach(mixed $ids=null): int {
        $parentKey=$this->parent->getAttribute($this->parentKey);
        if($parentKey===null)
            throw new OrmException('Cannot detach related records: parent is not persisted.',100731);
        $query=Query::delete()->from($this->pivotTable)->where($this->foreignPivotKey,$parentKey);
        if($ids!==null) {
            $ids=is_array($ids)?$ids:array($ids);
            $query->whereIn($this->relatedPivotKey,array_values($ids));
        }
        return $this->db->query($query)->getAffectedRows();
    }

    /**
     * 同步关联(对账中间表)
     *
     * - 插入缺失、删除多余, 使中间表恰为目标集合
     * - $detaching=false 时只增不删(保留未在列表中的已有关联)
     * - 返回同步后的相关主键列表
     *
     * @access public
     * @param mixed $ids 目标相关主键(或 [主键=>附加字段] 映射)
     * @param bool $detaching 是否删除不在目标集合中的已有关联
     * @return array 同步后的相关主键列表
     * @throws OrmException 父模型未持久化
     */
    public function sync(mixed $ids,bool $detaching=true): array {
        $parentKey=$this->parent->getAttribute($this->parentKey);
        if($parentKey===null)
            throw new OrmException('Cannot sync related records: parent is not persisted.',100732);
        $rows=$this->buildPivotRows($parentKey,$ids);
        $targetIds=array();
        foreach($rows as $row)
            $targetIds[$row[$this->relatedPivotKey]]=true;
        $currentIds=$this->queryPivotKeys($parentKey);
        // 待删除: 当前有但目标无
        $toDetach=array();
        foreach($currentIds as $id) {
            if(!isset($targetIds[$id]))
                $toDetach[]=$id;
        }
        if($detaching&&!empty($toDetach)) {
            $this->db->query(
                Query::delete()->from($this->pivotTable)
                    ->where($this->foreignPivotKey,$parentKey)
                    ->whereIn($this->relatedPivotKey,$toDetach)
            );
        }
        // 待插入: 目标有但当前无
        $toAttach=array();
        foreach($rows as $row) {
            if(!in_array($row[$this->relatedPivotKey],$currentIds,true))
                $toAttach[]=$row;
        }
        if(!empty($toAttach))
            $this->db->query(Query::insert($this->padPivotRows($toAttach))->from($this->pivotTable));
        return array_keys($targetIds);
    }

    /**
     * 归一化 $ids 为中间表行(统一含父键与关联键)
     *
     * @access private
     * @param mixed $parentKey 父模型主键值
     * @param mixed $ids 主键 / 主键数组 / [主键=>附加字段] 映射
     * @return array
     */
    private function buildPivotRows(mixed $parentKey,mixed $ids): array {
        $map=array();
        if(is_array($ids)) {
            $isList=(array_keys($ids)===range(0,count($ids)-1));
            if($isList) {
                foreach($ids as $id)
                    $map[$id]=array();
            } else {
                foreach($ids as $id=>$attrs)
                    $map[$id]=is_array($attrs)?$attrs:array();
            }
        } else {
            $map[$ids]=array();
        }
        $rows=array();
        foreach($map as $id=>$attrs) {
            $rows[]=array_merge(array(
                $this->foreignPivotKey=>$parentKey,
                $this->relatedPivotKey=>$id,
            ),$attrs);
        }
        return $rows;
    }

    /**
     * 统一中间表行列(补齐缺失键为 null, 支持一次多行插入)
     *
     * @access private
     * @param array $rows 中间表行列表
     * @return array
     */
    private function padPivotRows(array $rows): array {
        $columns=array();
        foreach($rows as $row) {
            foreach(array_keys($row) as $col)
                $columns[$col]=true;
        }
        $columns=array_keys($columns);
        $padded=array();
        foreach($rows as $row) {
            $paddedRow=array();
            foreach($columns as $col)
                $paddedRow[$col]=$row[$col]??null;
            $padded[]=$paddedRow;
        }
        return $padded;
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
        $parentKey=$this->parent->getAttribute($this->parentKey);
        if($parentKey===null)
            return false; // 父模型未持久化, 无关联
        $keys=$this->queryPivotKeys($parentKey);
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
