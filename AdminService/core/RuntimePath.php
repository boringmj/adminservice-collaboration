<?php

namespace AdminService;

use base\RuntimePathInterface;

/**
 * 默认运行时路径解析器
 *
 * - 委托 `Runtime::path()`，保持现有 RUNTIME_PATH 解析规则
 * - 组件依赖本契约后可注入固定路径实现，不直接读取静态 Runtime
 */
final class RuntimePath implements RuntimePathInterface {

    /**
     * 获取 runtime 根目录
     *
     * @access public
     * @return string
     */
    public function path(): string {
        return Runtime::path();
    }

}
