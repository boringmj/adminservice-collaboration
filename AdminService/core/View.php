<?php

namespace AdminService;

use base\View as BaseView;
use AdminService\Exception;

final class View extends BaseView {

    /**
     * 初始化方法
     * 
     * @access public
     * @param string $template_path 模板文件路径
     * @param array $data 需要传递给模板的数据
     * @return void
     */
    public function init(string $template_path,array $data=array()): void {
        if(!is_file($template_path))
            throw new Exception('Template file not found.',100301,array(
                'template_path'=>$template_path
            )
        );
        $this->template_path=$template_path;
        $this->data=$data;
    }

    /**
     * 渲染模板
     * 
     * @access public
     * @return string
     */
    public function render(): string {
        ob_start();
        // 将文件读取到变量中
        $content=file_get_contents($this->template_path);
        // 将变量中的内容替换为数据
        foreach($this->data as $key=>$value) {
            $content=str_replace('{{'.$key.'}}',$value,$content);
        }
        ob_end_clean();
        return $content;
    }

}

?>