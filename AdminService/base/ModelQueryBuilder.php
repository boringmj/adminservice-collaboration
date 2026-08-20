<?php

namespace base;

use base\Database\Db;
use base\Database\Query\Query;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Definition\Field;
use base\Database\Sql\Definition\Table;
use base\Database\Type\StatementType;

use function array_map;
use function count;

/**
 * 模型查询构建器
 *
 * - 包装 DBAL 查询对象, 为模型提供链式查询与结果水合
 * - 终端操作: get() 返回模型集合, first()/find() 返回单个模型
 */
class ModelQueryBuilder {

    /**
     * DBAL 查询对象
     * @var Query
     */
    protected Query $query;

    /**
     * 模型类名
     * @var string
     */
    protected string $modelClass;

    /**
     * 数据库入口
     * @var Db
     */
    protected Db $db;

    /**
     * 上一次执行结果
     * @var ResultInterface|null
     */
    protected ?ResultInterface $lastResult=null;

    /**
     * 主表别名
     * @var string|null
     */
    protected ?string $alias=null;

    /**
     * 构造方法
     *
     * @access public
     * @param Db $db 数据库入口
     * @param string $modelClass 模型类名
     */
    public function __construct(Db $db,string $modelClass) {
        $this->db=$db;
        $this->modelClass=$modelClass;
        $this->query=Query::select();
    }

    /**
     * 获取 DBAL 查询对象(高级用法/调试)
     *
     * @access public
     * @return Query
     */
    public function getQuery(): Query {
        return $this->query;
    }

    /**
     * 设置主表
     *
     * @access public
     * @param string|Table $table 表名
     * @param string|null $alias 别名
     * @return static
     */
    public function from(string|Table $table,?string $alias=null): static {
        $this->query->from($table,$alias);
        return $this;
    }

    /**
     * 设置查询字段
     *
     * @access public
     * @param string|Field|array $columns 字段
     * @param string|null $alias 别名
     * @return static
     */
    public function field(string|Field|array $columns,?string $alias=null): static {
        $this->query->field($columns,$alias);
        return $this;
    }

    /**
     * 去重
     *
     * @access public
     * @return static
     */
    public function distinct(): static {
        $this->query->distinct();
        return $this;
    }

    /**
     * 追加条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param mixed $value 值
     * @param string $operator 操作符
     * @return static
     */
    public function where(string|Field $field,mixed $value=null,string $operator='='): static {
        $this->query->where($field,$value,$operator);
        return $this;
    }

    /**
     * 追加 IN 条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param array $values 值列表
     * @param bool $not 是否取反
     * @return static
     */
    public function whereIn(string|Field $field,array $values,bool $not=false): static {
        $this->query->whereIn($field,$values,$not);
        return $this;
    }

    /**
     * 追加 NOT IN 条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param array $values 值列表
     * @return static
     */
    public function whereNotIn(string|Field $field,array $values): static {
        $this->query->whereNotIn($field,$values);
        return $this;
    }

    /**
     * 追加 BETWEEN 条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param mixed $min 最小值
     * @param mixed $max 最大值
     * @param bool $not 是否取反
     * @return static
     */
    public function whereBetween(string|Field $field,mixed $min,mixed $max,bool $not=false): static {
        $this->query->whereBetween($field,$min,$max,$not);
        return $this;
    }

    /**
     * 追加 IS NULL 条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param bool $not 是否取反
     * @return static
     */
    public function whereNull(string|Field $field,bool $not=false): static {
        $this->query->whereNull($field,$not);
        return $this;
    }

    /**
     * 追加分组条件
     *
     * @access public
     * @param string $connector 连接符号
     * @param callable $callback 回调
     * @return static
     */
    public function whereGroup(string $connector,callable $callback): static {
        $this->query->whereGroup($connector,$callback);
        return $this;
    }

    /**
     * 追加关联查询
     *
     * @access public
     * @param string $type 关联类型
     * @param string|Table $table 关联表
     * @param array $on 关联条件
     * @return static
     */
    public function join(string $type,string|Table $table,array $on): static {
        $this->query->join($type,$table,$on);
        return $this;
    }

    /**
     * 追加排序
     *
     * @access public
     * @param string|Field $field 字段
     * @param string $direction 方向
     * @return static
     */
    public function order(string|Field $field,string $direction='ASC'): static {
        $this->query->order($field,$direction);
        return $this;
    }

    /**
     * 追加排序(别名)
     *
     * @access public
     * @param string|Field $field 字段
     * @param string $direction 方向
     * @return static
     */
    public function orderBy(string|Field $field,string $direction='ASC'): static {
        return $this->order($field,$direction);
    }

    /**
     * 追加分组
     *
     * @access public
     * @param string|Field $field 字段
     * @return static
     */
    public function group(string|Field $field): static {
        $this->query->group($field);
        return $this;
    }

    /**
     * 追加分组(别名)
     *
     * @access public
     * @param string|Field $field 字段
     * @return static
     */
    public function groupBy(string|Field $field): static {
        return $this->group($field);
    }

    /**
     * 设置数量限制
     *
     * @access public
     * @param int $limit 限制
     * @param int|null $offset 偏移
     * @return static
     */
    public function limit(int $limit,?int $offset=null): static {
        $this->query->limit($limit,$offset);
        return $this;
    }

    /**
     * 设置偏移量
     *
     * @access public
     * @param int $offset 偏移
     * @return static
     */
    public function offset(int $offset): static {
        $this->query->offset($offset);
        return $this;
    }

    /**
     * 设置行锁
     *
     * @access public
     * @param string $type 锁类型
     * @return static
     */
    public function lock(string $type='update'): static {
        $this->query->lock($type);
        return $this;
    }

    /**
     * 设置主表别名
     *
     * - 在终端操作 prepareTable 时应用到模型表名
     *
     * @access public
     * @param string $alias 别名
     * @return static
     */
    public function alias(string $alias): static {
        $this->alias=$alias;
        return $this;
    }

    /**
     * 查询并返回模型集合
     *
     * @access public
     * @return ModelCollection
     */
    public function get(): ModelCollection {
        $this->prepareTable();
        $this->query->type(StatementType::SELECT);
        $result=$this->run();
        $models=array();
        foreach($result->getResults() as $row)
            $models[]=($this->modelClass)::newFromRow($row);
        return new ModelCollection($this->modelClass,$models);
    }

    /**
     * 查询单条
     *
     * @access public
     * @return Model|null
     */
    public function first(): ?Model {
        $this->prepareTable();
        $this->query->type(StatementType::FIND);
        $result=$this->run();
        $rows=$result->getResults()->toArray();
        if(empty($rows))
            return null;
        return ($this->modelClass)::newFromRow($rows[0]);
    }

    /**
     * 按主键查询单条
     *
     * @access public
     * @param mixed $id 主键值
     * @return Model|null
     */
    public function find(mixed $id): ?Model {
        return $this->where(($this->modelClass)::primaryKey(),$id)->first();
    }

    /**
     * 统计数量
     *
     * @access public
     * @return int
     */
    public function count(): int {
        $this->prepareTable();
        $this->query->type(StatementType::COUNT);
        $result=$this->run();
        $rows=$result->getResults()->toArray();
        return (int)($rows[0]['__count']??0);
    }

    /**
     * 查询单个字段的值
     *
     * @access public
     * @param string $field 字段名
     * @return mixed
     */
    public function value(string $field): mixed {
        $model=$this->field($field)->first();
        return $model?->getAttribute($field);
    }

    /**
     * 查询某字段的值列表
     *
     * @access public
     * @param string $field 字段名
     * @return array
     */
    public function pluck(string $field): array {
        return array_map(function($model) use ($field) {
            return $model->getAttribute($field);
        },$this->get()->all());
    }

    /**
     * 创建并保存一条记录
     *
     * @access public
     * @param array $data 数据
     * @return Model
     */
    public function create(array $data): Model {
        $model=new ($this->modelClass)();
        $model->fill($data);
        $result=$this->db->query(
            Query::insert($model->getAttributes())->from(($this->modelClass)::tableName())
        );
        if($result->isSuccess()) {
            $model->setAttribute(($this->modelClass)::primaryKey(),$result->getLastInsertId());
            $model->markExists();
        }
        return $model;
    }

    /**
     * 按当前条件更新
     *
     * @access public
     * @param array $data 数据
     * @return int 受影响行数
     */
    public function update(array $data): int {
        $this->prepareTable();
        $this->query->type(StatementType::UPDATE)->sets($data);
        return $this->run()->getAffectedRows();
    }

    /**
     * 按当前条件删除
     *
     * @access public
     * @return int 受影响行数
     */
    public function delete(): int {
        $this->prepareTable();
        $this->query->type(StatementType::DELETE);
        return $this->run()->getAffectedRows();
    }

    /**
     * 获取上一次执行的 SQL
     *
     * @access public
     * @return string
     */
    public function getLastSql(): string {
        return $this->lastResult?->getSql()??'';
    }

    /**
     * 为主表设置模型对应表名
     *
     * @access private
     * @return void
     */
    private function prepareTable(): void {
        $this->query->from(($this->modelClass)::tableName(),$this->alias);
    }

    /**
     * 执行查询
     *
     * @access private
     * @return ResultInterface
     */
    private function run(): ResultInterface {
        $result=$this->db->query($this->query);
        $this->lastResult=$result;
        return $result;
    }

}
