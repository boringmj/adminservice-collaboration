<?php

namespace base\Database\Sql\Type;

/**
 * 编译特性
 *
 * - 仅保留已实现的特性; 新增能力时在此声明并在编译器消费
 */
class CompileFeature {

    /**
     * 内联参数(参数值渲染为 SQL 字面量而非占位符)
     * @var int
     */
    public const INLINE_PARAM=0x04;

}
