<?php

namespace AdminService;

use AdminService\View\AllowedRoots;
use AdminService\View\Engine;
use AdminService\View\Source\FileSource;
use AdminService\View\Source\StringSource;
use base\View as BaseView;
use base\View\TemplateSourceInterface;

use function realpath;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * 视图门面
 *
 * - 解析、编译与缓存由 `AdminService\View` 子模块承担
 * - 引擎在第一次渲染时创建, 控制器构造阶段不加载
 *
 * @access public
 * @package AdminService
 * @version 2.0.0
 */
final class View extends BaseView {

    /**
     * 模板来源
     * @var TemplateSourceInterface|null
     */
    private ?TemplateSourceInterface $source=null;

    /**
     * 模板引擎
     * @var Engine|null
     */
    private ?Engine $engine=null;

    /**
     * 初始化方法
     *
     * @access public
     * @param string $template_path 模板文件路径
     * @param array<string,mixed> $data 需要传递给模板的数据
     * @return void
     * @throws Exception 文件不存在、不可读或路径越界
     */
    public function init(string $template_path,array $data=array()): void {
        $this->assertAllowedPath($template_path);
        $this->source=new FileSource($template_path);
        $this->data=$data;
    }

    /**
     * 通过直接传入模板内容完成初始化
     *
     * @access public
     * @param string $template_content 模板内容
     * @param array<string,mixed> $data 需要传递给模板的数据
     * @return void
     */
    public function initWithContent(string $template_content,array $data=array()): void {
        $this->source=new StringSource($template_content);
        $this->data=$data;
    }

    /**
     * 通过字符串内容初始化(兼容入口)
     *
     * @access public
     * @param string $content 模板内容
     * @param array<string,mixed> $data 模板数据
     * @return void
     */
    public function initByContent(string $content,array $data): void {
        $this->initWithContent($content,$data);
    }

    /**
     * 渲染模板
     *
     * - 未初始化时按构造器或 `setTemplatePath()` 记录的路径加载, 校验与 `init()` 一致
     *
     * @access public
     * @return string
     * @throws Exception 未初始化、文件不存在、不可读或路径越界
     */
    public function render(): string {
        if($this->source===null) {
            if(!isset($this->template_path)||$this->template_path==='')
                throw new Exception('View not initialized.',100303);
            $this->init($this->template_path,$this->data);
        }
        $this->engine??=new Engine();
        return $this->engine->render($this->source,$this->data);
    }

    /**
     * 设置模板文件路径
     *
     * - 使已初始化的来源失效, 下一次渲染按新路径加载
     *
     * @access public
     * @param string $template_path 模板文件路径
     * @return void
     */
    public function setTemplatePath(string $template_path): void {
        parent::setTemplatePath($template_path);
        $this->source=null;
    }

    /**
     * 清理模板编译产物
     *
     * @access public
     * @return bool
     */
    public function clearCache(): bool {
        $this->engine??=new Engine();
        return $this->engine->clear();
    }

    /**
     * 校验模板路径
     *
     * - 只允许 `app.path`、错误模板目录与 `view.allowed_paths` 下的普通文件
     * - 配置不可用时跳过; 允许根不可用、包装器路径与越界路径报 `100304`, 本地文件不存在报 `100301`
     *
     * @access private
     * @param string $path 模板路径
     * @return void
     * @throws Exception 文件不存在、路径越界或不是普通文件
     */
    private function assertAllowedPath(string $path): void {
        $config=Config::repository();
        if($config===null)
            return;
        $roots=AllowedRoots::fromConfig($config);
        if($roots===array())
            throw new Exception('Template allowed roots are unavailable.',100304,array(
                'template_path'=>$path
            ));
        $real=realpath($path);
        if($real===false) {
            // 包装器路径无法按本地路径判断存在性, 仍按路径策略拒绝
            if(str_contains($path,'://'))
                throw new Exception('Template path must be a regular file under allowed roots.',100304,array(
                    'template_path'=>$path
                ));
            throw new Exception('Template file not found.',100301,array(
                'template_path'=>$path
            ));
        }
        foreach($roots as $root) {
            $prefix=rtrim($root,'/\\').DIRECTORY_SEPARATOR;
            if($real===$root||str_starts_with($real,$prefix))
                return;
        }
        throw new Exception('Template path is outside allowed roots.',100304,array(
            'template_path'=>$path
        ));
    }

}
