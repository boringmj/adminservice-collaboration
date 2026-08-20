<?php

namespace base\Database\Sql\Definition;

/**
 * 语句定义
 *
 * - 不可变值对象, 由语句构建器从查询对象快照而来
 * - 是编译器(编译器)的唯一输入, 描述一次语义完整的数据库操作
 */
final class StatementDefinition implements StatementDefinitionInterface {

    /**
     * 语句类型
     * @see \base\Database\Type\StatementType
     * @var int
     */
    private int $type;

    /**
     * 主表
     * @var Table|null
     */
    private ?Table $table;

    /**
     * 查询字段列表(空数组表示全部字段)
     * @var array
     */
    private array $columns;

    /**
     * 是否去重
     * @var bool
     */
    private bool $distinct;

    /**
     * 关联查询列表
     * @var Join[]
     */
    private array $joins;

    /**
     * 查询条件(可为空)
     * @var Where|null
     */
    private ?Where $where;

    /**
     * 分组字段列表
     * @var Field[]
     */
    private array $group;

    /**
     * 排序字段列表
     * @var array
     */
    private array $order;

    /**
     * 查询数量限制
     * @var int|null
     */
    private ?int $limit;

    /**
     * 查询偏移量
     * @var int|null
     */
    private ?int $offset;

    /**
     * 行锁类型(shared/update)
     * @var string|null
     */
    private ?string $lock;

    /**
     * 插入的数据行列表(INSERT)
     * @var array
     */
    private array $rows;

    /**
     * 更新的数据列(UPDATE)
     * @var array
     */
    private array $sets;

    /**
     * 构造方法
     *
     * @access public
     * @param int $type 语句类型
     * @param Table|null $table 主表
     * @param array $columns 查询字段列表
     * @param bool $distinct 是否去重
     * @param array $joins 关联查询列表
     * @param Where|null $where 查询条件
     * @param array $group 分组字段列表
     * @param array $order 排序字段列表
     * @param int|null $limit 查询数量限制
     * @param int|null $offset 查询偏移量
     * @param string|null $lock 行锁类型
     * @param array $rows 插入的数据行列表
     * @param array $sets 更新的数据列
     */
    public function __construct(
        int $type,
        ?Table $table=null,
        array $columns=array(),
        bool $distinct=false,
        array $joins=array(),
        ?Where $where=null,
        array $group=array(),
        array $order=array(),
        ?int $limit=null,
        ?int $offset=null,
        ?string $lock=null,
        array $rows=array(),
        array $sets=array()
    ) {
        $this->type=$type;
        $this->table=$table;
        $this->columns=$columns;
        $this->distinct=$distinct;
        $this->joins=$joins;
        $this->where=$where;
        $this->group=$group;
        $this->order=$order;
        $this->limit=$limit;
        $this->offset=$offset;
        $this->lock=$lock;
        $this->rows=$rows;
        $this->sets=$sets;
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
     * - 每个元素为 array{0: Field, 1: string|null}
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
     * 获取查询条件
     *
     * @access public
     * @return Where|null
     */
    public function getWhere(): ?Where {
        return $this->where;
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
     * - 每个元素为 array{0: Field, 1: string}
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
     * 获取插入的数据行列表(INSERT)
     *
     * @access public
     * @return array
     */
    public function getRows(): array {
        return $this->rows;
    }

    /**
     * 获取更新的数据列(UPDATE)
     *
     * @access public
     * @return array
     */
    public function getSets(): array {
        return $this->sets;
    }

}
