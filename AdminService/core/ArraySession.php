<?php

namespace AdminService;

use base\AbstractSession;

use function is_array;

/**
 * 数组会话驱动
 *
 * - 数据只在内存中: 不开启原生会话、不下发会话Cookie, 请求结束即消失
 * - 语义与主流框架的 array 驱动一致, 用于「需要会话接口但不需要跨请求持久化」的场景
 * - 未显式开启原生会话时的默认驱动, 避免出现"看似写入成功实则丢失"的假会话
 */
final class ArraySession extends AbstractSession {

    /**
     * 会话数据
     * @var array<string,mixed>
     */
    protected array $data=array();

    /**
     * 初始化(内存驱动无需额外动作)
     *
     * @access public
     * @return void
     */
    public function init(): void { }

    /**
     * 获取Session ID(内存驱动无会话ID)
     *
     * @access public
     * @return string
     */
    public function getId(): string {
        return $this->session_id??'';
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
        if(is_array($params)) {
            foreach($params as $key=>$val)
                $this->data[$key]=$val;
        } else $this->data[$params]=$value;
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
        return $this->data[$name]??$default;
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
                unset($this->data[$key]);
        } else unset($this->data[$params]);
    }

    /**
     * 清空Session信息
     *
     * @access public
     * @return void
     */
    public function clear(): void {
        $this->data=array();
    }

    /**
     * 销毁Session
     *
     * @access public
     * @return void
     */
    public function destroy(): void {
        $this->data=array();
        $this->session_id=null;
    }

}
