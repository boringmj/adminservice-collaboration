<?php

namespace base\Database\Query;

use base\Database\Exception\QueryException;
use base\Database\Sql\Definition\Field;
use base\Database\Sql\Definition\Join;
use base\Database\Sql\Definition\Literal;
use base\Database\Sql\Definition\Table;
use base\Database\Sql\Definition\Where;
use base\Database\Type\StatementType;

use function array_map;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function str_contains;
use function str_replace;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * 查询对象(可变流式构建器)
 *
 * - 实现 QueryInterface, 语义化描述一次数据库操作
 * - 由语句构建器快照为不可变的 StatementDefinition 后交给编译器
 */
class Query implements QueryInterface {

    /**
     * 关联条件允许的操作符
     * @var array
     */
    private const JOIN_OPERATORS=array(
        '=',
        '!=',
        '<>',
        '>',
        '<',
        '>=',
        '<=',
        'LIKE',
        'NOT LIKE',
    );

    /**
     * 语句类型
     * @see \base\Database\Type\StatementType
     * @var int
     */
    private int $type=StatementType::SELECT;

    /**
     * 主表
     * @var Table|null
     */
    private ?Table $table=null;

    /**
     * 查询字段列表
     * @var array
     */
    private array $columns=array();

    /**
     * 是否去重
     * @var bool
     */
    private bool $distinct=false;

    /**
     * 关联查询列表
     * @var Join[]
     */
    private array $joins=array();

    /**
     * 查询条件根节点(AND 分组)
     * @var Where|null
     */
    private ?Where $where=null;

    /**
     * 分组字段列表
     * @var Field[]
     */
    private array $group=array();

    /**
     * 排序字段列表
     * @var array
     */
    private array $order=array();

    /**
     * 查询数量限制
     * @var int|null
     */
    private ?int $limit=null;

    /**
     * 查询偏移量
     * @var int|null
     */
    private ?int $offset=null;

    /**
     * 行锁类型(shared/update)
     * @var string|null
     */
    private ?string $lock=null;

    /**
     * 插入的数据行列表
     * @var array
     */
    private array $rows=array();

    /**
     * 更新的数据列
     * @var array
     */
    private array $sets=array();

    /**
     * 创建查询语句
     *
     * @access public
     * @return static
     */
    public static function select(): static {
        return (new static())->type(StatementType::SELECT);
    }

    /**
     * 创建单条查询语句
     *
     * @access public
     * @return static
     */
    public static function find(): static {
        return (new static())->type(StatementType::FIND);
    }

    /**
     * 创建统计语句
     *
     * @access public
     * @return static
     */
    public static function count(): static {
        return (new static())->type(StatementType::COUNT);
    }

    /**
     * 创建插入语句
     *
     * @access public
     * @param array $rows 插入的数据行列表(单行可传关联数组)
     * @return static
     */
    public static function insert(array $rows=array()): static {
        $query=new static();
        $query->type=StatementType::INSERT;
        // 兼容单行关联数组
        if(isset($rows[0])&&is_array($rows[0]))
            $query->rows=$rows;
        elseif(!empty($rows))
            $query->rows=array($rows);
        return $query;
    }

    /**
     * 创建更新语句
     *
     * @access public
     * @param array $sets 更新的数据列
     * @return static
     */
    public static function update(array $sets=array()): static {
        $query=new static();
        $query->type=StatementType::UPDATE;
        $query->sets=$sets;
        return $query;
    }

    /**
     * 创建删除语句
     *
     * @access public
     * @return static
     */
    public static function delete(): static {
        return (new static())->type(StatementType::DELETE);
    }

    /**
     * 设置语句类型
     *
     * @access public
     * @param int $type 语句类型
     * @return static
     */
    public function type(int $type): static {
        $this->type=$type;
        return $this;
    }

    /**
     * 设置主表
     *
     * @access public
     * @param string|Table $table 表名或表引用(字符串支持 "users" 与 "users u")
     * @param string|null $alias 表别名
     * @return static
     */
    public function from(string|Table $table,?string $alias=null): static {
        $this->table=$this->parseTable($table,$alias);
        return $this;
    }

    /**
     * 设置主表(table 别名)
     *
     * @access public
     * @param string|Table $table 表名或表引用
     * @param string|null $alias 表别名
     * @return static
     */
    public function table(string|Table $table,?string $alias=null): static {
        return $this->from($table,$alias);
    }

    /**
     * 设置查询字段
     *
     * @access public
     * @param string|Field|array $columns 字段(支持 "a", "a.b", "a,b", ["a","b"], ["a"=>"alias"])
     * @param string|null $alias 字段别名(仅字符串字段生效)
     * @return static
     */
    public function field(string|Field|array $columns,?string $alias=null): static {
        // 星号表示全部字段, 忽略(与旧驱动行为一致)
        if($columns==='*')
            return $this;
        if(is_array($columns)) {
            foreach($columns as $key=>$value) {
                if(is_string($key))
                    $this->addColumn($this->resolveField($key),$value);
                else
                    $this->addColumn($this->resolveField($value),null);
            }
            return $this;
        }
        // 逗号分隔的多个字段
        if(is_string($columns)&&str_contains($columns,',')) {
            foreach(explode(',',$columns) as $column) {
                $column=trim($column);
                if($column!=='')
                    $this->addColumn($this->resolveField($column),null);
            }
            return $this;
        }
        $this->addColumn($this->resolveField($columns),$alias);
        return $this;
    }

    /**
     * 追加查询字段
     *
     * @access private
     * @param Field $field 字段引用
     * @param string|null $alias 字段别名
     * @return void
     */
    private function addColumn(Field $field,?string $alias): void {
        $this->columns[]=array($field,$alias);
    }

    /**
     * 去重
     *
     * @access public
     * @return static
     */
    public function distinct(): static {
        $this->distinct=true;
        return $this;
    }

    /**
     * 追加普通条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param mixed $value 操作数
     * @param string $operator 操作符(不区分大小写)
     * @return static
     */
    public function where(string|Field $field,mixed $value=null,string $operator='='): static {
        return $this->addWhere(Where::leaf($this->resolveField($field),$operator,$value));
    }

    /**
     * 追加 IN 条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param array $values 值列表(不可为空)
     * @param bool $not 是否取反(NOT IN)
     * @return static
     */
    public function whereIn(string|Field $field,array $values,bool $not=false): static {
        if(empty($values))
            throw new QueryException('IN requires a non-empty array.',100509,array(
                'field'=>(string)$field
            ));
        return $this->addWhere(Where::leaf($this->resolveField($field),$not?'NOT IN':'IN',$values));
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
        return $this->whereIn($field,$values,true);
    }

    /**
     * 追加 BETWEEN 条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param mixed $min 最小值
     * @param mixed $max 最大值
     * @param bool $not 是否取反(NOT BETWEEN)
     * @return static
     */
    public function whereBetween(string|Field $field,mixed $min,mixed $max,bool $not=false): static {
        return $this->addWhere(Where::leaf(
            $this->resolveField($field),
            $not?'NOT BETWEEN':'BETWEEN',
            array($min,$max)
        ));
    }

    /**
     * 追加 IS NULL 条件
     *
     * @access public
     * @param string|Field $field 字段
     * @param bool $not 是否取反(IS NOT NULL)
     * @return static
     */
    public function whereNull(string|Field $field,bool $not=false): static {
        return $this->addWhere(Where::leaf(
            $this->resolveField($field),
            $not?'IS NOT NULL':'IS NULL',
            null
        ));
    }

    /**
     * 追加分组条件
     *
     * @access public
     * @param string $connector 连接符号(and/or)
     * @param callable $callback 子条件构建回调(接收一个子查询对象)
     * @return static
     */
    public function whereGroup(string $connector,callable $callback): static {
        $sub=new static();
        $callback($sub);
        $wheres=$sub->getWheres();
        // 空分组回调不产生任何条件, 忽略
        if(empty($wheres))
            return $this;
        return $this->addWhere(Where::group($connector,$wheres));
    }

    /**
     * 追加条件节点
     *
     * @access public
     * @param Where $where 条件节点
     * @return static
     */
    public function addWhere(Where $where): static {
        if($this->where===null) {
            $this->where=Where::group('AND',array($where));
            return $this;
        }
        if($this->where->isGroup()) {
            $conditions=$this->where->getConditions();
            $conditions[]=$where;
            $this->where=Where::group($this->where->getConnector(),$conditions);
            return $this;
        }
        $this->where=Where::group('AND',array($this->where,$where));
        return $this;
    }

    /**
     * 追加关联查询
     *
     * - 关联条件为 [左字段, 操作符, 右字段] 或 ["left"=>..., "operator"=>..., "right"=>...],
     *   左右字段均可为字符串或 Field
     *
     * @access public
     * @param string $type 关联类型(inner/left/right/full)
     * @param string|Table $table 关联表("orders" 或 "orders o")
     * @param array $on 关联条件列表
     * @return static
     */
    public function join(string $type,string|Table $table,array $on): static {
        $join_table=$this->parseTable($table);
        $conditions=array();
        foreach($on as $value) {
            if(!is_array($value))
                throw new QueryException('Invalid join condition.',100512,array(
                    'condition'=>$value
                ));
            if(isset($value['left'])) {
                $left=$value['left'];
                $operator=$value['operator']??'=';
                $right=$value['right']??null;
            } else {
                $left=$value[0]??null;
                $operator=$value[1]??'=';
                $right=$value[2]??null;
            }
            // 左侧必须为字段引用; 右侧可为字段引用、字面量或标量值
            if($left===null||(!is_string($left)&&!$left instanceof Field))
                throw new QueryException('Invalid join condition left side.',100512,array(
                    'condition'=>$value
                ));
            if($right===null||(!is_scalar($right)&&!$right instanceof Field&&!$right instanceof Literal))
                throw new QueryException('Invalid join condition right side.',100512,array(
                    'condition'=>$value
                ));
            $operator=strtoupper($operator);
            if(!in_array($operator,self::JOIN_OPERATORS,true))
                throw new QueryException('Unsupported join operator.',100512,array(
                    'condition'=>$value,
                    'operator'=>$operator
                ));
            $conditions[]=array(
                $this->resolveField($left),
                $operator,
                // 字符串与字段引用视为列, Literal 与其余标量视为参数值
                ($right instanceof Field||is_string($right))
                    ? $this->resolveField($right)
                    : $right
            );
        }
        $this->joins[]=new Join($type,$join_table,$conditions);
        return $this;
    }

    /**
     * 追加排序
     *
     * @access public
     * @param string|Field $field 字段
     * @param string $direction 排序方向(asc/desc)
     * @return static
     */
    public function order(string|Field $field,string $direction='ASC'): static {
        $direction=strtoupper($direction);
        if(!in_array($direction,array('ASC','DESC'),true))
            throw new QueryException('Invalid order direction.',100513,array(
                'direction'=>$direction
            ));
        $this->order[]=array($this->resolveField($field),$direction);
        return $this;
    }

    /**
     * 追加排序(order 别名)
     *
     * @access public
     * @param string|Field $field 字段
     * @param string $direction 排序方向
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
        $this->group[]=$this->resolveField($field);
        return $this;
    }

    /**
     * 追加分组(group 别名)
     *
     * @access public
     * @param string|Field $field 字段
     * @return static
     */
    public function groupBy(string|Field $field): static {
        return $this->group($field);
    }

    /**
     * 设置查询数量限制
     *
     * @access public
     * @param int $limit 数量限制
     * @param int|null $offset 偏移量
     * @return static
     */
    public function limit(int $limit,?int $offset=null): static {
        if($limit<0||($offset!==null&&$offset<0))
            throw new QueryException('Invalid limit/offset.',100514,array(
                'limit'=>$limit,
                'offset'=>$offset
            ));
        $this->limit=$limit;
        if($offset!==null)
            $this->offset=$offset;
        return $this;
    }

    /**
     * 设置偏移量
     *
     * @access public
     * @param int $offset 偏移量
     * @return static
     */
    public function offset(int $offset): static {
        if($offset<0)
            throw new QueryException('Invalid offset.',100514,array(
                'offset'=>$offset
            ));
        $this->offset=$offset;
        return $this;
    }

    /**
     * 设置行锁
     *
     * @access public
     * @param string $type 锁类型(shared/update, 不区分大小写)
     * @return static
     */
    public function lock(string $type='update'): static {
        $type=strtolower($type);
        if(!in_array($type,array('shared','update'),true))
            throw new QueryException('Invalid lock type.',100515,array(
                'type'=>$type
            ));
        $this->lock=$type;
        return $this;
    }

    /**
     * 设置插入的数据行
     *
     * @access public
     * @param array $rows 数据行列表
     * @return static
     */
    public function rows(array $rows): static {
        $this->rows=$rows;
        return $this;
    }

    /**
     * 设置更新的数据列
     *
     * @access public
     * @param array $sets 数据列
     * @return static
     */
    public function sets(array $sets): static {
        $this->sets=$sets;
        return $this;
    }

    /**
     * 获取语句类型
     *
     * @access public
     * @return int
     */
    public function getType(): int {
        return $this->type;
    }

    /**
     * 获取主表
     *
     * @access public
     * @return Table|null
     */
    public function getTable(): ?Table {
        return $this->table;
    }

    /**
     * 获取查询字段列表
     *
     * @access public
     * @return array
     */
    public function getColumns(): array {
        return $this->columns;
    }

    /**
     * 是否去重
     *
     * @access public
     * @return bool
     */
    public function isDistinct(): bool {
        return $this->distinct;
    }

    /**
     * 获取关联查询列表
     *
     * @access public
     * @return Join[]
     */
    public function getJoins(): array {
        return $this->joins;
    }

    /**
     * 获取查询条件根节点
     *
     * @access public
     * @return Where|null
     */
    public function getWhere(): ?Where {
        return $this->where;
    }

    /**
     * 获取查询条件节点列表
     *
     * @access public
     * @return Where[]
     */
    public function getWheres(): array {
        if($this->where===null)
            return array();
        if($this->where->isGroup())
            return $this->where->getConditions();
        return array($this->where);
    }

    /**
     * 是否存在查询条件
     *
     * @access public
     * @return bool
     */
    public function hasWhere(): bool {
        return !empty($this->getWheres());
    }

    /**
     * 获取分组字段列表
     *
     * @access public
     * @return Field[]
     */
    public function getGroup(): array {
        return $this->group;
    }

    /**
     * 获取排序字段列表
     *
     * @access public
     * @return array
     */
    public function getOrder(): array {
        return $this->order;
    }

    /**
     * 获取查询数量限制
     *
     * @access public
     * @return int|null
     */
    public function getLimit(): ?int {
        return $this->limit;
    }

    /**
     * 获取查询偏移量
     *
     * @access public
     * @return int|null
     */
    public function getOffset(): ?int {
        return $this->offset;
    }

    /**
     * 获取行锁类型
     *
     * @access public
     * @return string|null
     */
    public function getLock(): ?string {
        return $this->lock;
    }

    /**
     * 获取插入的数据行列表
     *
     * @access public
     * @return array
     */
    public function getRows(): array {
        return $this->rows;
    }

    /**
     * 获取更新的数据列
     *
     * @access public
     * @return array
     */
    public function getSets(): array {
        return $this->sets;
    }

    /**
     * 解析字段引用
     *
     * @access private
     * @param string|Field $field 字段
     * @return Field
     * @throws QueryException
     */
    private function resolveField(string|Field $field): Field {
        if($field instanceof Field)
            return $field;
        return $this->parseField($field);
    }

    /**
     * 解析字符串字段为字段引用
     *
     * @access private
     * @param string $field 字段(支持 "col" 与 "table.col")
     * @return Field
     * @throws QueryException
     */
    private function parseField(string $field): Field {
        $parts=explode('.',str_replace('`','',trim($field)));
        $parts=array_map('trim',$parts);
        // 结构校验(方言无关), 标识符合法字符由方言在编译期校验
        if(count($parts)===1&&$parts[0]!=='')
            return Field::column($parts[0]);
        if(count($parts)===2&&$parts[0]!==''&&$parts[1]!=='')
            return Field::qualified($parts[0],$parts[1]);
        throw new QueryException('Invalid field format.',100506,array(
            'field'=>$field
        ));
    }

    /**
     * 解析表引用
     *
     * - 结构校验(方言无关), 标识符合法字符由方言在编译期校验
     *
     * @access private
     * @param string|Table $table 表(支持 "users" 与 "users u")
     * @param string|null $alias 表别名
     * @return Table
     * @throws QueryException
     */
    private function parseTable(string|Table $table,?string $alias=null): Table {
        if($table instanceof Table) {
            if($alias!==null)
                return Table::aliased($table->getName(),$alias);
            return $table;
        }
        $parts=explode(' ',trim($table));
        if(count($parts)>2)
            throw new QueryException('Invalid table format.',100507,array(
                'table'=>$table
            ));
        $name=trim($parts[0],'`');
        if($name==='')
            throw new QueryException('Invalid table format.',100507,array(
                'table'=>$table
            ));
        if(isset($parts[1]))
            $alias=$alias??trim($parts[1],'`');
        return $alias!==null&&$alias!==''
            ? Table::aliased($name,$alias)
            : Table::name($name);
    }

}
