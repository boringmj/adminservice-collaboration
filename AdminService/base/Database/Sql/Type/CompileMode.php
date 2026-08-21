<?php

namespace base\Database\Sql\Type;

/**
 * 编译模式
 */
class CompileMode {

    /**
     * 内联参数(将参数值渲染为 SQL 字面量)
     * @var int
     */
    public const INLINE_PARAM=0x02;

}
