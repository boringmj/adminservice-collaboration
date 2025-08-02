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
     * @param array $data 需要传递给模板的数据
     * @return void
     */
    abstract public function init(string $template_path,array $data=array()): void;

    /**
     * 通过直接传入模板内容完成初始化
     * 
     * @access public
     * @param string $template_content 模板内容
     * @param array $data 需要传递给模板的数据
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
     * 一旦初始化完成后修改模板路径将不会生效,除非重新初始化,但这会造成数据丢失和性能损耗
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
     * @access public
     * @param string|null $template_path 模板文件路径
     * @param array $data 需要传递给模板的数据
     */
    final public function __construct(?string $template_path=null,array $data=array()) {
        if($template_path!==null)
            $this->setTemplatePath($template_path);
        $this->data=$data;
    }

}