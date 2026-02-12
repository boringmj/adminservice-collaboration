<?php

namespace base\Database\Sql\Type;

/**
 * 编译特性
 */
class CompileFeature {

    /**
     * 自动限制一条记录
     * @var int
     */
    public const AUTO_LIMIT_ONE=0x01;

    /**
     * 强制别名
     * @var int
     */
    public const FORCE_ALIAS=0x02;
    /**
     * 内联参数
     * @var int
     */
    public const INLINE_PARAM=0x04;

    /**
     * 调试SQL
     * @var int
     */
    public const DEBUG_SQL=0x08;

    /**
     * 严格模式
     * @var int
     */
    public const STRICT_MODE=0x10;

    /**
     * UPSERT
     */
    public const UPSERT=0x20;

    /**
     * 返回生成的主键
     */
    public const RETURNING_PK=0x40;

    /**
     * 跳过锁定
     */
    public const SKIP_LOCKED=0x80;

}