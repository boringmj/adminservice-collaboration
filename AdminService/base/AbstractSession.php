<?php

namespace base;

/**
 * Session处理类
 */
abstract class AbstractSession {

    /**
     * Session ID
     * @var string|null
     */
    protected ?string $session_id=null;

    /**
     * 设置Session ID
     * 
     * @access public
     * @param string $session_id Session ID
     * @return void
     */
    public function setId(string $session_id): void {
        $this->session_id=$session_id;
    }

    /**
     * 初始化
     * 
     * @access public
     * @return void
     */
    abstract public function init(): void;

    /**
     * 获取Session ID
     * 
     * @access public
     * @return string
     */
    abstract public function getId(): string;
    
    /**
     * 设置Session信息
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value 参数值
     * @return void
     */
    abstract public function set(string|array $params,string $value): void;

    /**
     * 获取Session信息
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function get(string $name,mixed $default=null): mixed;

    /**
     * 删除Session信息
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @return void
     */
    abstract public function delete(string|array $params): void;

    /**
     * 清空Session信息
     * 
     * @access public
     * @return void
     */
    abstract public function clear(): void;

    /**
     * 销毁Session
     * 
     * @access public
     * @return void
     */
    abstract public function destroy(): void;

}