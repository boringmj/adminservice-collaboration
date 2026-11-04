<?php

namespace base;

/**
 * 运行时路径解析契约
 *
 * - 供需要默认写入 runtime 目录的组件按依赖注入取路径
 * - 默认实现见 `AdminService\RuntimePath`；测试可注入固定路径
 */
interface RuntimePathInterface {

    /**
     * 获取 runtime 根目录
     *
     * @access public
     * @return string
     */
    public function path(): string;

}
