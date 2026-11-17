<?php

namespace AdminService\View;

use AdminService\Config;
use AdminService\View\Cache\FileCache;
use AdminService\View\Cache\NullCache;
use AdminService\View\Compiler\PhpCompiler;
use base\View\CodeTemplateCompilerInterface;
use base\View\TemplateCacheInterface;
use base\View\TemplateCompilerInterface;
use base\View\TemplateSourceInterface;

/**
 * 模板引擎
 *
 * - 组合来源、编译器与缓存; 由门面在第一次渲染时创建
 *
 * @access public
 * @package AdminService\View
 * @version 1.0.0
 */
final class Engine {

    /**
     * 编译器
     * @var TemplateCompilerInterface
     */
    private TemplateCompilerInterface $compiler;

    /**
     * 缓存
     * @var TemplateCacheInterface
     */
    private TemplateCacheInterface $cache;

    /**
     * 构造方法
     *
     * @access public
     * @param TemplateCompilerInterface|null $compiler 编译器(null = PHP 编译器)
     * @param TemplateCacheInterface|null $cache 缓存(null = 按配置选择)
     */
    public function __construct(?TemplateCompilerInterface $compiler=null,?TemplateCacheInterface $cache=null) {
        $this->compiler=$compiler??new PhpCompiler();
        if($cache!==null) {
            $this->cache=$cache;
            return;
        }
        if(!$this->compiler instanceof CodeTemplateCompilerInterface) {
            $this->cache=new NullCache();
            return;
        }
        $config=Config::repository();
        $this->cache=$config===null?new NullCache():new FileCache($config);
    }

    /**
     * 渲染模板
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @param array<string,mixed> $data 数据
     * @return string
     */
    public function render(TemplateSourceInterface $source,array $data): string {
        return $this->cache->load($source,$this->compiler)->render($data);
    }

    /**
     * 清理编译产物
     *
     * @access public
     * @return bool
     */
    public function clear(): bool {
        return $this->cache->clear();
    }

}
