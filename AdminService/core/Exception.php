<?php

namespace AdminService;

use base\Exception as BaseException;
use \ReflectionException;

final class Exception extends BaseException {

    /**
     * 构造方法
     *
     * @access public
     * @param string $message
     * @param int $error_code
     * @param array $data
     * @throws Exception
     * @throws ReflectionException
     */
    final public function __construct(string $message,int $error_code=0,array $data=array()) {
        parent::__construct($message,$error_code,$data);
        //写入日志
        App::get('Log')->write(
            'Error({error_code}): {message} | data: {data} in {file} on line {line}, trace: {trace}',
            array(
                'message'=>$message,
                'error_code'=>$error_code,
                'data'=>json_encode($data),
                'file'=>$this->getFile(),
                'line'=>$this->getLine(),
                'trace'=>$this->getTraceAsString()
            )
        );
    }

    /**
     * 输出错误信息
     * 
     * @access public
     * @return void
     */
    public function echo(): void {
        echo $this->error_code.':'.$this->getMessage()."<br>\n";
        echo "File: ".$this->getFile()."<br>\n";
        echo "Line: ".$this->getLine()."<br>\n";
        echo "Trace: ".$this->getTraceAsString()."<br>\n";
    }

    /**
     * 错误事件触发器
     * 
     * @access public
     * @param callable $callback 回调事件
     * @return mixed
     */
    public function trigger(callable $callback): mixed {
        return $callback($this);
    }

    /**
     * 返回错误信息
     * 
     * @access public
     * @return array
     */
    public function returnError(): array {
        return array(
            'error_code'=>$this->error_code,
            'message'=>$this->getMessage()
        );
    }

}