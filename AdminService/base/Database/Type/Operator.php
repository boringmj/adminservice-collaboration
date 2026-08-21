<?php

namespace base\Database\Type;

/**
 * SQL 操作符常量(通用 ANSI SQL)
 *
 * - 供 where()/join() 的操作符参数使用; 亦可直接传字面量(内部统一转大写)
 */
final class Operator {

    /**
     * 等于
     * @var string
     */
    public const EQ='=';

    /**
     * 等于(别名, 兼容 == 写法)
     * @var string
     */
    public const EQ_ALT='==';

    /**
     * 不等于
     * @var string
     */
    public const NEQ='!=';

    /**
     * 不等于(ANSI 标准拼写, 与 != 同义)
     * @var string
     */
    public const NEQ_ANSI='<>';

    /**
     * 大于
     * @var string
     */
    public const GT='>';

    /**
     * 大于等于
     * @var string
     */
    public const GTE='>=';

    /**
     * 小于
     * @var string
     */
    public const LT='<';

    /**
     * 小于等于
     * @var string
     */
    public const LTE='<=';

    /**
     * 模糊匹配
     * @var string
     */
    public const LIKE='LIKE';

    /**
     * 模糊不匹配
     * @var string
     */
    public const NOT_LIKE='NOT LIKE';

    /**
     * 在集合内(whereIn, 或 where(field, 数组, Operator::IN))
     * @var string
     */
    public const IN='IN';

    /**
     * 不在集合内
     * @var string
     */
    public const NOT_IN='NOT IN';

    /**
     * 区间内(whereBetween, 或 where(field, [min,max], Operator::BETWEEN))
     * @var string
     */
    public const BETWEEN='BETWEEN';

    /**
     * 区间外
     * @var string
     */
    public const NOT_BETWEEN='NOT BETWEEN';

    /**
     * 为空(where(field, null) 自动转)
     * @var string
     */
    public const IS_NULL='IS NULL';

    /**
     * 非空
     * @var string
     */
    public const IS_NOT_NULL='IS NOT NULL';

}
