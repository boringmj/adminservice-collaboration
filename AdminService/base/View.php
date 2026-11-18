<?php

namespace base;

abstract class View {

    /**
     * 模板文件路径
     * @var string
     */
    protected string $template_path;

    /**
     * 需要传递给模板的数据
     * @var array
     */
    protected array $data;

    /**
     * 初始化方法
     * 
     * @access public
     * @param string $template_path 模板文件路径
     * @param array<string,mixed> $data 需要传递给模板的数据
     * @return void
     */
    abstract public function init(string $template_path,array $data=array()): void;

    /**
     * 通过直接传入模板内容完成初始化
     * 
     * @access public
     * @param string $template_content 模板内容
     * @param array<string,mixed> $data 需要传递给模板的数据
     * @return void
     */
    abstract public function initWithContent(string $template_content,array $data=array()): void;

    /**
     * 渲染模板
     * 
     * @access public
     * @return string
     */
    abstract public function render(): string;

    /**
     * 设置模板文件路径
     *
     * - 实现应在后续渲染时使用该路径(已初始化来源的实现可使其失效)
     *
     * @access public
     * @param string $template_path 模板文件路径
     * @return void
     */
    public function setTemplatePath(string $template_path): void {
        $this->template_path=$template_path;
    }

    /**
     * 构造方法
     *
     * - 传入路径时经 `setTemplatePath()` 记录, 由实现决定加载时机
     *
     * @access public
     * @param string|null $template_path 模板文件路径
     * @param array<string,mixed> $data 需要传递给模板的数据
     */
    final public function __construct(?string $template_path=null,array $data=array()) {
        if($template_path!==null)
            $this->setTemplatePath($template_path);
        $this->data=$data;
    }

}