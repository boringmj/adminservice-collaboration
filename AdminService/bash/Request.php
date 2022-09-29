<?php

namespace bash;

class Request {

    /**
     * 结束前的数据
     */
    static private $data_exit;

    /**
     * 请求参数
     */
    static private $request_params;

    /**
     * 结束运行
     * 
     * @access public
     * @param string $message
     * @return void
     */
    final static function requestExit(string $message='') {
        self::$data_exit=array(
            'message'=>$message
        );
        exit();
    }

    /**
     * 结束时输出内容
     */
    final static function requestEcho(string $message='') {
        echo self::$data_exit['message'];
    }

    /**
     * 获取或设置请求参数(传入数组则设置请求参数)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @return mixed
     */
    static public function params(int|string|array $params,mixed $value='')
    {
        if(is_array($params))
            self::$request_params=$params;
        else if(empty($value))
            return self::$request_params[$params]??'';
        else
            self::$request_params[$params]=$value;
    }

    /**
     * 获取参数
     * 
     * @access public
     * @param int|string $params 参数
     * @return mixed
     */
    static public function get(int|string $params)
    {
        return self::$request_params[$params]??'';
    }

    /**
     * 设置参数
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $value 值
     * @return void
     */
    static public function set(int|string $params, mixed $value)
    {
        self::$request_params[$params]=$value;
    }
}

?>