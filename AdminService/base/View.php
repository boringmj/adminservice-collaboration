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
     * 渲染模板
     * 
     * @access public
     * @return string
     */
    abstract public function render(): string;

    /**
     * 设置模板文件路径
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
     * @param string $template_path 模板文件路径
     * @param array $data 需要传递给模板的数据
     */
    final public function __construct(?string $template_path=null,$data=array()) {
        if($template_path!==null)
            $this->setTemplatePath($template_path);
        $this->data=$data;
    }

}

?>