<?php

namespace base\Database\Sql\Definition;

/**
 * 字段引用(列名)
 *
 * - 不可变值对象
 * - table 为 null 时表示未限定的列名
 */
final class Field {

    /**
     * 所属表名(可为空)
     * @var string|null
     */
    private ?string $table;

    /**
     * 列名
     * @var string
     */
    private string $column;

    /**
     * 构造方法
     *
     * @access public
     * @param string|null $table 所属表名
     * @param string $column 列名
     */
    public function __construct(?string $table,string $column) {
        $this->table=$table;
        $this->column=$column;
    }

    /**
     * 创建未限定的列引用
     *
     * @access public
     * @param string $column 列名
     * @return static
     */
    public static function column(string $column): static {
        return new static(null,$column);
    }

    /**
     * 创建带表限定的列引用
     *
     * @access public
     * @param string $table 所属表名
     * @param string $column 列名
     * @return static
     */
    public static function qualified(string $table,string $column): static {
        return new static($table,$column);
    }

    /**
     * 获取所属表名
     *
     * @access public
     * @return string|null
     */
    public function getTable(): ?string {
        return $this->table;
    }

    /**
     * 获取列名
     *
     * @access public
     * @return string
     */
    public function getColumn(): string {
        return $this->column;
    }

    /**
     * 是否带表限定
     *
     * @access public
     * @return bool
     */
    public function isQualified(): bool {
        return $this->table!==null;
    }

    /**
     * 转字符串
     *
     * @access public
     * @return string
     */
    public function __toString(): string {
        return $this->table!==null
            ? $this->table.'.'.$this->column
            : $this->column;
    }

}
