<?php

namespace Tests\Fixtures;

use base\AbstractSession;
use RuntimeException;

/**
 * 初始化阶段抛异常的会话驱动
 *
 * - 用于验证请求装配失败后的错误状态清理
 */
final class InitThrowingSession extends AbstractSession {

    /**
     * 初始化
     *
     * @access public
     * @return void
     */
    public function init(): void {
        throw new RuntimeException('session init failed');
    }

    /**
     * 获取会话 ID
     *
     * @access public
     * @return string
     */
    public function getId(): string {
        return '';
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
        return $default;
    }

    /**
     * 删除会话信息
     *
     * @access public
     * @param string|array<int,string> $params 参数名或参数组
     * @return void
     */
    public function delete(string|array $params): void {
    }

    /**
     * 清空会话信息
     *
     * @access public
     * @return void
     */
    public function clear(): void {
    }

    /**
     * 销毁会话
     *
     * @access public
     * @return void
     */
    public function destroy(): void {
    }

}
