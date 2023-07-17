<?php

namespace AdminService;

use AdminService\Config;
use \Exception;

final class Log {
    
    /**
    * 日志文件路径
    * @var string
    */
    private string $log_path;

    /**
    * 构造方法
    * 
    * @access public
    * @param string $log_name 日志文件名称(不含文件扩展名,不含目录名)
    */
    public function __construct(?string $log_name=null) {
        // 获取用于存储日志的目录
        $log_path=Config::get('log.path');
        // 如果目录不存在,则创建目录
        if(!is_dir($log_path))
            mkdir($log_path,Config::get('log.dir_mode'),true);
        // 如果日志名称为空,则使用默认日志名称
        if(empty($log_name))
            $log_name=$this->bind(Config::get('log.default_file'),array(
                'date'=>date('Y-m-d',time())
            ));
        // 判断日志名称是否合法
        if(!preg_match(Config::get('log.rule.file'),$log_name))
            throw new Exception('日志名称不合法'.$log_name);
        // 拼接上日志文件路径
        $log_path.='/'.$log_name.Config::get('log.ext_name');
        $this->log_path=$log_path;
        $this->check();
    }

    /**
     * 写入日志
     * 
     * @access public
     * @param string $content 日志格式(支持变量绑定,变量格式为{变量名},变量需要按数组形式传入)
     * @param array $vars 变量 例如:array('变量名'=>'变量值')这种形式传入,请注意传入顺序,{name}的值为'{value}',则会被再一次进行变量绑定,所以请注意变量的顺序
     * @return void
     */
    public function write(string $content,array $vars=array()):void {
        // 获取日志格式
        $format=Config::get('log.row');
        // 检查日志文件是否存在,可写和大小是否超过最大值
        $this->check();
        // 如果日志格式为空,则直接写入日志
        if(empty($format)) {
            file_put_contents($this->log_path,$this->bind($content,$vars).PHP_EOL,FILE_APPEND);
            return;
        }
        // 如果日志格式不为空,则进行变量绑定
        $content=$this->bind($format,array(
            'date'=>date('Y-m-d',time()),
            'time'=>date('H:i:s',time()),
            'msg'=>$this->bind($content,$vars)
        ));
        // 将换行符替换为"\\n"
        $eol=array(
            "\\"=>"\\\\",
            "\n"=>"\\n",
            "\r"=>"\\r"
        );
        $content=str_replace(array_keys($eol),array_values($eol),$content);
        // 写入日志
        file_put_contents($this->log_path,$content.PHP_EOL,FILE_APPEND);
    }

    /**
     * 检查日志文件是否存在,可写饥和大小是否超过最大值
     * 
     * @access private
     * @return void
     */
    private function check() {
        // 判断日志文件是否存在,如果不存在,则创建日志文件
        if(!is_file($this->log_path))
            file_put_contents($this->log_path,'');
        // 判断日志文件大小是否超过最大值
        if(filesize($this->log_path)>Config::get('log.max_size',104857600)) {
            $log_path_info=pathinfo($this->log_path);
            $log_path=$log_path_info['dirname'].'/'.$log_path_info['filename'];
            $log_ext_name=$log_path_info['extension'];
            // 获取日志文件名
            $log_name=$log_path_info['filename'];
            // 通过正则表达式获取日志结尾的“(int)”
            preg_match('/\((\d+)\)$/',$log_name,$match);
            // 如果日志结尾的“(int)”存在,则将“(int)”加1,否则直接在日志文件名后面加上“(int)”
            if(!empty($match))
                $log_name=preg_replace('/\((\d+)\)$/','('.($match[1]+1).')',$log_name);
            else
                $log_name.='(1)';
            // 重新拼接日志文件路径
            $this->log_path=dirname($log_path).'/'.$log_name.'.'.$log_ext_name;
            // 递归调用检查日志文件是否存在,最大值不超过99
            if(isset($match[1])&&$match[1]<99)
                $this->check();
            else if(isset($match[1])&&$match[1]>=99)
                throw new Exception('日志文件数量超过最大值');
            // 文件不存在,则创建文件
            if(!is_file($this->log_path))
                file_put_contents($this->log_path,'');
        }
        // 判断日志文件是否可写
        if(!is_writable($this->log_path))
            throw new Exception('日志文件不可写');
    }

    /**
     * 字符串与变量绑定
     * 
     * @access private
     * @param string $string 字符串
     * @param array $vars 变量
     * @return string
     */
    private function bind(string $string,array $vars):string {
        // 如果变量为空,则直接返回字符串
        if(empty($vars))
            return $string;
        // 遍历变量
        foreach($vars as $key=>$value) {
            if(!is_string($value))
                $value=json_encode($value,JSON_UNESCAPED_UNICODE);
            $string=str_replace('{'.$key.'}',$value,$string);
        }
        // 返回替换后的字符串
        return $string;
    }

}

?>