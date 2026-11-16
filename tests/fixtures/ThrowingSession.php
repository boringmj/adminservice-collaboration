<?php

namespace Tests\Fixtures;

use base\AbstractSession;
use RuntimeException;

/**
 * 收尾阶段抛异常的会话驱动
 *
 * - 用于验证常驻 worker 在响应写出后不回传收尾异常
 */
final class ThrowingSession extends AbstractSession {

    /**
     * 会话数据
     * @var array<string,mixed>
     */
    private array $data=array();

    /**
     * 初始化
     *
     * @access public
     * @return void
     */
    public function init(): void {
    }

    /**
     * 获取会话 ID
     *
     * @access public
     * @return string
     */
    public function getId(): string {
        return (string)($this->session_id??'');
    }

    /**
     * 设置会话信息
     *
     * @access public
     * @param string|array<string,mixed> $params 参数名或参数组
     * @param string $value 参数值
     * @return void
     */
    public function set(string|array $params,string $value): void {
        if(is_array($params)) {
            foreach($params as $name=>$item)
                $this->data[$name]=$item;
            return;
        }
        $this->data[$params]=$value;
    }

    /**
     * 获取会话信息
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
     * 删除会话信息
     *
     * @access public
     * @param string|array<int,string> $params 参数名或参数组
     * @return void
     */
    public function delete(string|array $params): void {
        foreach((array)$params as $name)
            unset($this->data[$name]);
    }

    /**
     * 清空会话信息
     *
     * @access public
     * @return void
     */
    public function clear(): void {
        $this->data=array();
    }

    /**
     * 销毁会话
     *
     * @access public
     * @return void
     */
    public function destroy(): void {
        $this->data=array();
    }

    /**
     * 结束会话写入
     *
     * @access public
     * @return void
     */
    public function close(): void {
        throw new RuntimeException('session close failed');
    }

}
