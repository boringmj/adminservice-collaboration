<?php

namespace base\View;

/**
 * 模板编译产物缓存契约
 *
 * @access public
 * @package base\View
 * @version 1.0.0
 */
interface TemplateCacheInterface {

    /**
     * 读取编译产物, 未命中时编译并写入
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @param TemplateCompilerInterface $compiler 编译器
     * @return CompiledTemplateInterface
     */
    public function load(TemplateSourceInterface $source,TemplateCompilerInterface $compiler): CompiledTemplateInterface;

    /**
     * 清理缓存
     *
     * @access public
     * @return bool
     */
    public function clear(): bool;

}
