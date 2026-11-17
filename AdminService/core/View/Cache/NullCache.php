<?php

namespace AdminService\View\Cache;

use base\View\CompiledTemplateInterface;
use base\View\TemplateCacheInterface;
use base\View\TemplateCompilerInterface;
use base\View\TemplateSourceInterface;

/**
 * 空模板缓存
 *
 * - 编译关闭、目录不可写或测试时使用
 *
 * @access public
 * @package AdminService\View\Cache
 * @version 1.0.0
 */
final class NullCache implements TemplateCacheInterface {

    /**
     * 直接编译, 不写入文件
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @param TemplateCompilerInterface $compiler 编译器
     * @return CompiledTemplateInterface
     */
    public function load(TemplateSourceInterface $source,TemplateCompilerInterface $compiler): CompiledTemplateInterface {
        return $compiler->compile($source);
    }

    /**
     * 清理缓存
     *
     * @access public
     * @return bool
     */
    public function clear(): bool {
        return true;
    }

}
