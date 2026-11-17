<?php

namespace base\View;

/**
 * 模板编译器契约
 *
 * @access public
 * @package base\View
 * @version 1.0.0
 */
interface TemplateCompilerInterface {

    /**
     * 把模板来源编译为可渲染产物
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @return CompiledTemplateInterface
     */
    public function compile(TemplateSourceInterface $source): CompiledTemplateInterface;

}
