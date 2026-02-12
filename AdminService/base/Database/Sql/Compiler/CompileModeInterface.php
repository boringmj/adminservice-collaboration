<?php

namespace base\Database\Sql\Compiler;

/**
 * 编译模式接口
 * 
 * - 应该使用位掩码来表示模式
 * - 请使用常量定义模式标志
 */
interface CompileModeInterface {

    /**
     * 是否启用某个模式
     * 
     * - 需校验组合模式下模式冲突的情况
     * @param int $mode 模式标志
     * @see base\Database\Sql\Type\CompileMode
     * @return bool
     */
    public function isEnabled(int $mode): bool;
    
}