<?php

namespace AdminService;

use base\AbstractSession;

class NativeSession extends AbstractSession {

    /**
     * 初始化
     * 
     * @access public
     * @return void
     */
    public function init(): void {
        if(session_status()===PHP_SESSION_NONE) {
            session_start();
            $this->session_id=session_id();
        }
    }

    /**
     * 获取Session ID
     * 
     * @access public
     * @return string
     */
    public function getId(): string {
        return $this->session_id??session_id();
    }

    /**
     * 设置Session信息
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value 参数值
     * @return void
     */
    public function set(string|array $params,string $value): void {
        if(is_array($params)) {
            foreach($params as $key=>$val)
                $_SESSION[$key]=$val;
        } else $_SESSION[$params]=$value;
    }

    /**
     * 获取Session信息
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $name,mixed $default=null): mixed {
        return $_SESSION[$name]??$default;
    }

    /**
     * 删除Session信息
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @return void
     */
    public function delete(string|array $params): void {
        if(is_array($params)) {
            foreach($params as $key)
                unset($_SESSION[$key]);
        } else unset($_SESSION[$params]);
    }

    /**
     * 清空Session信息
     * 
     * @access public
     * @return void
     */
    public function clear(): void {
        $_SESSION=[];
    }

    /**
     * 销毁Session
     * 
     * @access public
     * @return void
     */
    public function destroy(): void {
        $_SESSION=[];
        if(session_id()!=='') session_destroy();
        $this->session_id=null;
    }
}