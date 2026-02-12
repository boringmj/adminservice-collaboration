<?php

namespace base\Database\Sql\Type;

/**
 * 编译模式
 */
class CompileMode {

    /**
     * 调试模式
     * @var int
     */
    public const DEBUG=0x01;
    
    /**
     * 内联参数
     * @var int
     */
    public const INLINE_PARAM=0x02;
    
    /**
     * 严格模式
     * @var int
     */
    public const STRICT=0x04;

    /**
     * 强制别名
     * @var int
     */
    public const FORCE_ALIAS=0x08;

    /**
     * 自动限制单条记录
     * @var int
     */
    public const AUTO_LIMIT_ONE=0x10;
    
}