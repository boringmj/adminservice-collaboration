<?php

namespace base;

use AdminService\Console\Input;
use AdminService\Console\Output;

/**
 * 命令行命令基类
 *
 * - 子类只需实现 `name()`、`description()` 与 `execute()`
 * - 退出码语义: `SUCCESS=0`、`FAILURE=1`、`INVALID=2`
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
abstract class AbstractCommand implements CommandInterface {

    /**
     * 成功
     */
    public const SUCCESS=0;

    /**
     * 执行失败
     */
    public const FAILURE=1;

    /**
     * 参数或命令无效
     */
    public const INVALID=2;

    /**
     * 命令别名
     *
     * @access public
     * @return array<int,string>
     */
    public function aliases(): array {
        return array();
    }

    /**
     * 需要接收值的选项
     *
     * @access public
     * @return array<int,string>
     */
    public function valueOptions(): array {
        return array();
    }

    /**
     * 布尔选项
     *
     * - 用于命令行拒绝未知选项; 普通命令按需覆盖
     *
     * @access public
     * @return array<int,string>
     */
    public function booleanOptions(): array {
        return array();
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return $this->name().' [arguments] [options]';
    }

    /**
     * 执行命令
     *
     * @access public
     * @param Input $input 已解析参数
     * @param Output $output 输出
     * @return int
     */
    abstract public function execute(Input $input,Output $output): int;

}
