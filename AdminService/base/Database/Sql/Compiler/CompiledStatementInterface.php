<?php

namespace base\Database\Sql\Compiler;

/**
 * 编译后的SQL语句接口
 */
interface CompiledStatementInterface {

    /**
     * 获取编译后的SQL
     * @return string
     */
    public function getSql(): string;

    /**
     * 获取查询参数
     * @return array
     */
    public function getParams(): array;

}