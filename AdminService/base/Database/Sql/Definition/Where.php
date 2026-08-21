<?php

namespace base\Database\Sql\Definition;

use base\Database\Exception\QueryException;
use base\Database\Type\Operator;

use function array_values;
use function count;
use function in_array;
use function is_array;
use function strtoupper;

/**
 * 条件节点
 *
 * - 不可变值对象
 * - 叶子节点: field + operator + value
 * - 分组节点: connector(AND/OR) + conditions(子条件数组)
 */
final class Where {

    /**
     * 允许的操作符
     * @var array
     */
    public const OPERATORS=array(
        Operator::EQ,
        Operator::NEQ,
        Operator::NEQ_ANSI,
        Operator::GT,
        Operator::LT,
        Operator::GTE,
        Operator::LTE,
        Operator::LIKE,
        Operator::NOT_LIKE,
        Operator::IN,
        Operator::NOT_IN,
        Operator::BETWEEN,
        Operator::NOT_BETWEEN,
        Operator::IS_NULL,
        Operator::IS_NOT_NULL,
    );

    /**
     * 字段引用(仅叶子节点)
     * @var Field|null
     */
    private ?Field $field;

    /**
     * 操作符(仅叶子节点, 大写)
     * @var string|null
     */
    private ?string $operator;

    /**
     * 操作数(仅叶子节点; IN/NOT IN 与 BETWEEN/NOT BETWEEN 为数组)
     * @var mixed
     */
    private mixed $value;

    /**
     * 连接符号(仅分组节点, 大写)
     * @var string|null
     */
    private ?string $connector;

    /**
     * 子条件(仅分组节点)
     * @var Where[]
     */
    private array $conditions;

    /**
     * 是否为分组节点
     * @var bool
     */
    private bool $is_group;

    /**
     * 构造方法(私有, 请使用静态工厂方法)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 创建叶子条件
     *
     * @access public
     * @param Field $field 字段引用
     * @param string $operator 操作符(不区分大小写)
     * @param mixed $value 操作数
     * @return static
     */
    public static function leaf(Field $field,string $operator,mixed $value): static {
        $operator=strtoupper($operator);
        if(!in_array($operator,self::OPERATORS,true))
            throw new QueryException('Unsupported where operator.',100508,array(
                'operator'=>$operator
            ));
        // 按操作符校验操作数类型
        if(in_array($operator,array(Operator::IN,Operator::NOT_IN),true)&&!is_array($value))
            throw new QueryException('IN operator requires an array value.',100509,array(
                'operator'=>$operator
            ));
        if(in_array($operator,array(Operator::BETWEEN,Operator::NOT_BETWEEN),true)
            &&(!is_array($value)||count($value)!==2))
            throw new QueryException('BETWEEN operator requires two values.',100510,array(
                'operator'=>$operator
            ));
        if(in_array($operator,array(Operator::IS_NULL,Operator::IS_NOT_NULL),true))
            $value=null; // 操作数无效, 置空
        elseif(!in_array($operator,array(Operator::IN,Operator::NOT_IN,Operator::BETWEEN,Operator::NOT_BETWEEN),true)&&is_array($value))
            throw new QueryException('Operator does not support array value.',100509,array(
                'operator'=>$operator
            ));
        $instance=new static();
        $instance->field=$field;
        $instance->operator=$operator;
        $instance->value=$value;
        $instance->connector=null;
        $instance->conditions=array();
        $instance->is_group=false;
        return $instance;
    }

    /**
     * 创建分组条件
     *
     * @access public
     * @param string $connector 连接符号(and/or, 不区分大小写)
     * @param array $conditions 子条件数组
     * @return static
     */
    public static function group(string $connector,array $conditions): static {
        $connector=strtoupper($connector);
        if($connector!=='AND'&&$connector!=='OR')
            throw new QueryException('Unsupported where connector.',100508,array(
                'connector'=>$connector
            ));
        $instance=new static();
        $instance->field=null;
        $instance->operator=null;
        $instance->value=null;
        $instance->connector=$connector;
        $instance->conditions=array_values($conditions);
        $instance->is_group=true;
        return $instance;
    }

    /**
     * 是否为分组节点
     *
     * @access public
     * @return bool
     */
    public function isGroup(): bool {
        return $this->is_group;
    }

    /**
     * 获取字段引用(仅叶子节点)
     *
     * @access public
     * @return Field|null
     */
    public function getField(): ?Field {
        return $this->field;
    }

    /**
     * 获取操作符(仅叶子节点)
     *
     * @access public
     * @return string|null
     */
    public function getOperator(): ?string {
        return $this->operator;
    }

    /**
     * 获取操作数(仅叶子节点)
     *
     * @access public
     * @return mixed
     */
    public function getValue(): mixed {
        return $this->value;
    }

    /**
     * 获取连接符号(仅分组节点)
     *
     * @access public
     * @return string|null
     */
    public function getConnector(): ?string {
        return $this->connector;
    }

    /**
     * 获取子条件(仅分组节点)
     *
     * @access public
     * @return Where[]
     */
    public function getConditions(): array {
        return $this->conditions;
    }

}
