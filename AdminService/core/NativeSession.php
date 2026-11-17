<?php

namespace AdminService;

use base\AbstractSession;

use function headers_sent;
use function ini_set;
use function is_array;
use function session_destroy;
use function session_id;
use function session_regenerate_id;
use function session_start;
use function session_status;
use function session_write_close;

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
            if(headers_sent())
                throw new Exception('会话无法启动: 响应头已发送');
            $this->configure();
            if(!session_start()&&session_status()!==PHP_SESSION_ACTIVE)
                throw new Exception('会话启动失败');
        }
        if(session_status()===PHP_SESSION_ACTIVE)
            $this->session_id=session_id();
    }

    /**
     * 应用会话加固配置
     *
     * - 必须在 `session_start()` 之前调用
     * - 显式调用过 `setId()` 时不启用严格模式, 由调用方负责该 ID 的可信性
     * - 只设置与框架行为无关的固定项; HTTPS 判定由部署的 `session.cookie_secure` 决定
     *
     * @access private
     * @return void
     * @throws Exception 配置写入失败
     */
    private function configure(): void {
        $options=array(
            // 显式 setId() 表示调用方已确认该 ID, 不再由严格模式替换
            'session.use_strict_mode'=>$this->session_id===null?'1':'0',
            'session.use_only_cookies'=>'1',
            'session.cookie_httponly'=>'1',
            'session.cookie_samesite'=>'Lax'
        );
        foreach($options as $name=>$value)
            if(ini_set($name,$value)===false)
                throw new Exception('会话加固设置失败: '.$name);
    }

    /**
     * 轮换会话 ID
     *
     * - 会话未开启或响应头已发送时抛异常
     *
     * @access public
     * @param bool $delete_old_session 是否删除旧会话数据
     * @return bool
     * @throws Exception 会话未开启、响应头已发送或轮换失败
     */
    public function regenerate(bool $delete_old_session=false): bool {
        if(session_status()!==PHP_SESSION_ACTIVE)
            throw new Exception('会话未开启: 请先调用 init() 开启原生会话');
        if(headers_sent())
            throw new Exception('会话 ID 无法轮换: 响应头已发送');
        if(!session_regenerate_id($delete_old_session))
            throw new Exception('会话 ID 轮换失败');
        $this->session_id=session_id();
        return true;
    }

    /**
     * 设置Session ID(同步到 PHP 原生会话)
     *
     * @access public
     * @param string $session_id Session ID
     * @return void
     */
    public function setId(string $session_id): void {
        if(session_status()===PHP_SESSION_ACTIVE)
            throw new Exception('会话已激活, 无法修改 Session ID');
        if(session_id($session_id)===false)
            throw new Exception('设置 Session ID 失败');
        $this->session_id=$session_id;
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
        if(session_status()===PHP_SESSION_ACTIVE)
            session_destroy();
        $_SESSION=[];
        $this->session_id=null;
    }

    /**
     * 结束当前请求的会话写入
     *
     * - 释放 PHP 原生会话写锁, 避免常驻 worker 跨请求阻塞
     * - 不销毁会话数据
     *
     * @access public
     * @return void
     */
    public function close(): void {
        if(session_status()===PHP_SESSION_ACTIVE)
            session_write_close();
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
