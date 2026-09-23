<?php

namespace AdminService;

use base\AbstractSession;

use function is_array;
use function session_destroy;
use function session_id;
use function session_start;
use function session_status;

/**
 * 原生会话驱动
 *
 * - 基于 PHP 原生 `$_SESSION`: 须先 {@see init()} 开启, 开启时下发会话Cookie
 * - 未开启就读写会抛异常, 避免"看似写入成功实则丢失"(需要无持久化的会话请改用 {@see ArraySession})
 */
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
     * @param string $value 参数值($params 参数为数组时此参数无效)
     * @return void
     */
    public function set(string|array $params,string $value): void {
        $this->assertStarted();
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
        $this->assertStarted();
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
        $this->assertStarted();
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
        $this->assertStarted();
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

    /**
     * 断言会话已开启
     *
     * @access private
     * @throws Exception 会话未开启
     * @return void
     */
    private function assertStarted(): void {
        if(session_status()!==PHP_SESSION_ACTIVE)
            throw new Exception('会话未开启: 请先调用 init() 开启原生会话, 或改用 AdminService\ArraySession');
    }

}
