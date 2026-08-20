<?php

namespace base\Database\Sql\Dialect;

/**
 * 内联参数转义能力接口
 *
 * - 实现该接口的方言可对内联参数模式(CompileMode::INLINE_PARAM)将值渲染为 SQL 字面量
 * - 未实现该接口的方言在启用内联参数模式时, 编译器应抛出异常而非降级
 */
interface InlineQuotingInterface {

    /**
     * 将值转义为 SQL 字面量
     *
     * @access public
     * @param mixed $value 值
     * @return string 转义后的字面量
     */
    public function quoteValue(mixed $value): string;

}
