<?php

namespace base\Database\Query;

use base\Database\Db;
use base\Database\Exception\QueryException;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Definition\Field;
use base\Database\Sql\Definition\Table;
use base\Database\Type\StatementType;

use function ceil;
use function max;

/**
 * 流式查询构建器(裸查询入口)
 *
 * - 持有 Db(执行)与语义 Query(构建), 每个入口调用新建, 链式状态隔离
 * - 构建方法委托给语义 Query 并返回自身; 终端读方法返回行数据(非模型), 写方法返回受影响行/自增主键
 * - 由数据库门面(如 AdminService\Db::table())产出
 */
class QueryBuilder {

    /**
     * 数据库入口(执行用)
     * @var Db
     */
    private Db $db;

    /**
     * 表名(写操作 insert 用)
     * @var string|Table
     */
    private string|Table $table;

    /**
     * 语义查询对象(构建用)
     * @var Query
     */
    private Query $query;

    /**
     * 上一次执行结果(getSql 用, 不重复执行)
     * @var ResultInterface|null
     */
    private ?ResultInterface $lastResult=null;

    /**
     * 构造方法
     *
     * @access public
     * @param Db $db 数据库入口
     * @param string|Table $table 表名
     * @param string|null $alias 表别名
     */
    public function __construct(Db $db,string|Table $table,?string $alias=null) {
        $this->db=$db;
        $this->table=$table;
        $this->query=Query::select()->from($table,$alias);
    }

    // ========== 构建方法(委托给语义查询, 返回自身支持链式) ==========

    /**
     * @access public
     * @param string|Field|array $columns 字段
     * @param string|null $alias 字段别名
     * @return static
     */
    public function field(string|Field|array $columns,?string $alias=null): static {
        $this->query->field($columns,$alias);
        return $this;
    }

    /**
     * @access public
     * @return static
     */
    public function distinct(): static {
        $this->query->distinct();
        return $this;
    }

    /**
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
     * @access public
     * @param string|Field $field 字段
     * @param array<mixed> $values 值列表
     * @param bool $not 是否取反
     * @return static
     */
    public function whereIn(string|Field $field,array $values,bool $not=false): static {
        $this->query->whereIn($field,$values,$not);
        return $this;
    }

    /**
     * @access public
     * @param string|Field $field 字段
     * @param array<mixed> $values 值列表
     * @return static
     */
    public function whereNotIn(string|Field $field,array $values): static {
        $this->query->whereNotIn($field,$values);
        return $this;
    }

    /**
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
     * @access public
     * @param string $connector 连接符号(and/or)
     * @param callable $callback 子条件回调
     * @return static
     */
    public function whereGroup(string $connector,callable $callback): static {
        $this->query->whereGroup($connector,$callback);
        return $this;
    }

    /**
     * @access public
     * @param string $type 关联类型(inner/left/right/full)
     * @param string|Table $table 关联表("orders" 或 "orders o")
     * @param array $on 关联条件列表
     * @return static
     */
    public function join(string $type,string|Table $table,array $on): static {
        $this->query->join($type,$table,$on);
        return $this;
    }

    /**
     * @access public
     * @param string|Field $field 字段
     * @param string $direction 排序方向(asc/desc)
     * @return static
     */
    public function order(string|Field $field,string $direction='ASC'): static {
        $this->query->order($field,$direction);
        return $this;
    }

    /**
     * @access public
     * @param string|Field $field 字段
     * @param string $direction 排序方向
     * @return static
     */
    public function orderBy(string|Field $field,string $direction='ASC'): static {
        return $this->order($field,$direction);
    }

    /**
     * @access public
     * @param string|Field $field 字段
     * @return static
     */
    public function group(string|Field $field): static {
        $this->query->group($field);
        return $this;
    }

    /**
     * @access public
     * @param string|Field $field 字段
     * @return static
     */
    public function groupBy(string|Field $field): static {
        return $this->group($field);
    }

    /**
     * @access public
     * @param int $limit 数量限制
     * @param int|null $offset 偏移量
     * @return static
     */
    public function limit(int $limit,?int $offset=null): static {
        $this->query->limit($limit,$offset);
        return $this;
    }

    /**
     * @access public
     * @param int $offset 偏移量
     * @return static
     */
    public function offset(int $offset): static {
        $this->query->offset($offset);
        return $this;
    }

    /**
     * @access public
     * @param string $type 锁类型(shared/update)
     * @return static
     */
    public function lock(string $type='update'): static {
        $this->query->lock($type);
        return $this;
    }

    // ========== 终端读方法 ==========

    /**
     * 执行并返回原始结果(供错误检查/进阶使用)
     *
     * @access public
     * @return ResultInterface
     */
    public function result(): ResultInterface {
        return $this->db->query($this->query);
    }

    /**
     * 查询行集合
     *
     * @access public
     * @return array<array<string,mixed>>
     * @throws QueryException 执行失败
     */
    public function get(): array {
        return $this->execute()->getResults()->toArray();
    }

    /**
     * 查询单行
     *
     * @access public
     * @return array<string,mixed>|null
     * @throws QueryException 执行失败
     */
    public function first(): ?array {
        $query=clone $this->query;
        $query->limit(1);
        $rows=$this->run($query)->getResults()->toArray();
        return $rows[0]??null;
    }

    /**
     * 统计数量
     *
     * @access public
     * @return int
     * @throws QueryException 执行失败
     */
    public function count(): int {
        $query=clone $this->query;
        $query->type(StatementType::COUNT);
        $rows=$this->run($query)->getResults()->toArray();
        return (int)($rows[0]['__count']??0);
    }

    /**
     * 查询单个字段的值
     *
     * @access public
     * @param string $field 字段名
     * @return mixed
     * @throws QueryException 执行失败
     */
    public function value(string $field): mixed {
        $row=$this->field($field)->first();
        return $row[$field]??null;
    }

    /**
     * 查询某字段的值列表
     *
     * @access public
     * @param string $field 字段名
     * @return array<mixed>
     * @throws QueryException 执行失败
     */
    public function pluck(string $field): array {
        $rows=$this->get();
        $values=array();
        foreach($rows as $row)
            $values[]=$row[$field]??null;
        return $values;
    }

    /**
     * 分页查询
     *
     * @access public
     * @param int $perPage 每页条数
     * @param int $page 当前页码(从 1 开始)
     * @return array{items:array,total:int,per_page:int,current_page:int,last_page:int}
     * @throws QueryException 执行失败
     */
    public function paginate(int $perPage=15,int $page=1): array {
        if($perPage<1)
            throw new QueryException('Invalid per page.',100712,array('per_page'=>$perPage));
        $countQuery=clone $this->query;
        $countQuery->type(StatementType::COUNT);
        $countRows=$this->run($countQuery)->getResults()->toArray();
        $total=(int)($countRows[0]['__count']??0);
        $page=max(1,$page);
        $items=$this->limit($perPage)->offset(($page-1)*$perPage)->get();
        return array(
            'items'=>$items,
            'total'=>$total,
            'per_page'=>$perPage,
            'current_page'=>$page,
            'last_page'=>$perPage>0?(int)ceil($total/$perPage):0,
        );
    }

    // ========== 终端写方法 ==========

    /**
     * 插入数据
     *
     * @access public
     * @param array<string,mixed> $data 数据
     * @return int 自增主键(无自增则返回 0)
     * @throws QueryException 执行失败
     */
    public function insert(array $data): int {
        $result=$this->db->query(Query::insert($data)->from($this->table));
        if(!$result->isSuccess())
            throw new QueryException($result->getError(),100712);
        return (int)$result->getLastInsertId();
    }

    /**
     * 按当前条件更新
     *
     * @access public
     * @param array<string,mixed> $data 数据
     * @return int 受影响行数
     * @throws QueryException 执行失败
     */
    public function update(array $data): int {
        $query=clone $this->query;
        $query->type(StatementType::UPDATE)->sets($data);
        return $this->run($query)->getAffectedRows();
    }

    /**
     * 按当前条件删除
     *
     * @access public
     * @return int 受影响行数
     * @throws QueryException 执行失败
     */
    public function delete(): int {
        $query=clone $this->query;
        $query->type(StatementType::DELETE);
        return $this->run($query)->getAffectedRows();
    }

    /**
     * 获取上一次执行的 SQL(不重复执行)
     *
     * @access public
     * @return string
     */
    public function getSql(): string {
        return $this->lastResult?->getSql()??'';
    }

    /**
     * 执行当前查询(失败抛异常)
     *
     * @access private
     * @return ResultInterface
     * @throws QueryException 执行失败
     */
    private function execute(): ResultInterface {
        return $this->run($this->query);
    }

    /**
     * 执行指定查询(失败抛异常)
     *
     * @access private
     * @param Query $query 查询对象
     * @return ResultInterface
     * @throws QueryException 执行失败
     */
    private function run(Query $query): ResultInterface {
        $result=$this->db->query($query);
        $this->lastResult=$result;
        if(!$result->isSuccess())
            throw new QueryException($result->getError(),100712);
        return $result;
    }

}
