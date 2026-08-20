<?php

namespace base\Database\Sql\Compiler;

/**
 * 编译后的SQL语句
 *
 * - 不可变值对象, 由编译器产出
 * - SQL 使用 ? 占位符(或按编译模式内联字面量), 参数按出现顺序排列
 */
final class CompiledStatement implements CompiledStatementInterface {

    /**
     * 编译后的SQL
     * @var string
     */
    private string $sql;

    /**
     * 查询参数
     * @var array
     */
    private array $params;

    /**
     * 构造方法
     *
     * @access public
     * @param string $sql 编译后的SQL
     * @param array $params 查询参数
     */
    public function __construct(string $sql,array $params=array()) {
        $this->sql=$sql;
        $this->params=$params;
    }

    /**
     * 获取编译后的SQL
     *
     * @access public
     * @return string
     */
    public function getSql(): string {
        return $this->sql;
    }

    /**
     * 获取查询参数
     *
     * @access public
     * @return array
     */
    public function getParams(): array {
        return $this->params;
    }

}
