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
        // 将文件读取到变量中
        $content=file_get_contents($this->template_path);
        // 提取出遍历结构
        $content=preg_replace_callback('/{{foreach\s+\$(\w+)\s+as\s+\$(\w+)}}(.*?){{\/foreach}}/s',function($matches) {
            $temp='';
            // 判断变量是否存在
            if(!isset($this->data[$matches[1]]))
                return $temp;
            foreach($this->data[$matches[1]] as $value) {
                $str=$matches[3];
                // 判断$value是否为字符串和数字,如果是则替换
                if(is_string($value)||is_numeric($value))
                    $str=str_replace('{{$'.$matches[2].'}}',$value,$str);
                // 如果$value是数组则再往下遍历
                if(is_array($value)) {
                    foreach($value as $key=>$value) {
                        // 判断$value是否为字符串和数字,如果是则替换
                        if(is_string($value)||is_numeric($value))
                            $str=str_replace('{{$'.$matches[2].'["'.$key.'"]}}',$value,$str);
                    }
                }
                $temp.=$str;
            }
            return $temp;
        },$content);
        // 将变量中的内容替换为数据
        foreach($this->data as $key=>$value) {
            // 判断$value是否为字符串和数字,如果是则替换
            if(is_string($value)||is_numeric($value))
                $content=str_replace('{{'.$key.'}}',$value,$content);
        }
        return $content;
    }

}

?>