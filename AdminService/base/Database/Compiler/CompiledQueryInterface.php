<?php

namespace base\Database\Compiler;

/**
 * 编译后的查询接口
 */
interface CompiledQueryInterface {

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