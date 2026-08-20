<?php

namespace base\Database\Sql\Definition;

use base\Database\Exception\QueryException;

use function array_keys;
use function array_values;
use function in_array;
use function strtolower;
use function strtoupper;

/**
 * 关联查询节点
 *
 * - 不可变值对象
 * - 关联条件为 [左字段, 操作符, 右字段或标量] 的数组
 */
final class Join {

    /**
     * 支持的关联类型
     * @var array
     */
    public const TYPES=array(
        'inner'=>'INNER JOIN',
        'left'=>'LEFT JOIN',
        'right'=>'RIGHT JOIN',
        'full'=>'FULL JOIN',
    );

    /**
     * 关联类型(小写)
     * @var string
     */
    private string $type;

    /**
     * 关联表
     * @var Table
     */
    private Table $table;

    /**
     * 关联条件列表
     * @var array
     */
    private array $conditions;

    /**
     * 允许的操作符
     * @var array
     */
    public const OPERATORS=array(
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
     * 构造方法
     *
     * @access public
     * @param string $type 关联类型(inner/left/right/full, 不区分大小写)
     * @param Table $table 关联表
     * @param array $conditions 关联条件列表
     * @throws QueryException
     */
    public function __construct(string $type,Table $table,array $conditions) {
        $type=strtolower($type);
        if(!in_array($type,array_keys(self::TYPES),true))
            throw new QueryException('Unsupported join type.',100511,array(
                'type'=>$type
            ));
        if(empty($conditions))
            throw new QueryException('Join requires at least one condition.',100512);
        foreach($conditions as $condition) {
            $operator=$condition[1]??null;
            if($operator===null||!in_array(strtoupper($operator),self::OPERATORS,true))
                throw new QueryException('Unsupported join operator.',100512,array(
                    'condition'=>$condition
                ));
        }
        $this->type=$type;
        $this->table=$table;
        $this->conditions=array_values($conditions);
    }

    /**
     * 获取关联类型(小写)
     *
     * @access public
     * @return string
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * 获取 SQL 关联关键字
     *
     * @access public
     * @return string
     */
    public function getKeyword(): string {
        return self::TYPES[$this->type];
    }

    /**
     * 获取关联表
     *
     * @access public
     * @return Table
     */
    public function getTable(): Table {
        return $this->table;
    }

    /**
     * 获取关联条件列表
     *
     * - 每个条件为 array{0: Field, 1: string, 2: Field|scalar|Literal}, 标量/Literal 右值在编译时作为参数绑定
     *
     * @access public
     * @return array
     */
    public function getConditions(): array {
        return $this->conditions;
    }

}
