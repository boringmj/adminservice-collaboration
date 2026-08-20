<?php

namespace base\Database\Sql\Definition;

/**
 * 字面量值
 *
 * - 不可变值对象, 用于显式标记一个值应作为字面量参数绑定, 而非标识符
 * - 典型用途: 关联条件 ON 中需要与字符串常量比较时(字符串默认视为字段引用)
 */
final class Literal {

    /**
     * 值
     * @var mixed
     */
    private mixed $value;

    /**
     * 构造方法
     *
     * @access public
     * @param mixed $value 值
     */
    public function __construct(mixed $value) {
        $this->value=$value;
    }

    /**
     * 创建字面量
     *
     * @access public
     * @param mixed $value 值
     * @return static
     */
    public static function of(mixed $value): static {
        return new static($value);
    }

    /**
     * 获取值
     *
     * @access public
     * @return mixed
     */
    public function getValue(): mixed {
        return $this->value;
    }

}
