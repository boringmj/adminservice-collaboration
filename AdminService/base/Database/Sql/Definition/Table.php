<?php

namespace base\Database\Sql\Definition;

/**
 * 表引用
 *
 * - 不可变值对象
 * - 表前缀由编译器在编译时通过编译上下文统一添加
 */
final class Table {

    /**
     * 表名
     * @var string
     */
    private string $name;

    /**
     * 表别名
     * @var string|null
     */
    private ?string $alias;

    /**
     * 构造方法
     *
     * @access public
     * @param string $name 表名
     * @param string|null $alias 表别名
     */
    public function __construct(string $name,?string $alias=null) {
        $this->name=$name;
        $this->alias=$alias;
    }

    /**
     * 创建不带别名的表引用
     *
     * @access public
     * @param string $name 表名
     * @return static
     */
    public static function name(string $name): static {
        return new static($name);
    }

    /**
     * 创建带别名的表引用
     *
     * @access public
     * @param string $name 表名
     * @param string $alias 表别名
     * @return static
     */
    public static function aliased(string $name,string $alias): static {
        return new static($name,$alias);
    }

    /**
     * 获取表名
     *
     * @access public
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * 获取表别名
     *
     * @access public
     * @return string|null
     */
    public function getAlias(): ?string {
        return $this->alias;
    }

    /**
     * 是否带别名
     *
     * @access public
     * @return bool
     */
    public function hasAlias(): bool {
        return $this->alias!==null;
    }

}
