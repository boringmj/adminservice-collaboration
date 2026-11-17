<?php

namespace base\View;

/**
 * 可生成 PHP 代码的模板编译器契约
 *
 * - 文件缓存需要把产物写成 PHP 文件后再加载
 *
 * @access public
 * @package base\View
 * @version 1.0.0
 */
interface CodeTemplateCompilerInterface extends TemplateCompilerInterface {

    /**
     * 生成 PHP 闭包代码
     *
     * - 返回值是 `static function(array $data): string {...}` 形式的表达式
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @return string
     */
    public function code(TemplateSourceInterface $source): string;

}
