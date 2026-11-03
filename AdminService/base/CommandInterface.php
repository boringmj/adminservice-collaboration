<?php

namespace base;

use AdminService\Console\Input;
use AdminService\Console\Output;

/**
 * 命令行命令契约
 *
 * - `name()` 返回规范命令路径, 如 `server start` / `cache build`
 * - `aliases()` 返回命令自身的别名, 如 `array('serve')`
 * - `valueOptions()` 返回需要接收值的选项名(长选项名与短选项字母)
 * - `booleanOptions()` 返回布尔选项名, 供 CLI 拒绝未声明的选项
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface CommandInterface {

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string;

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string;

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string;

    /**
     * 命令别名
     *
     * @access public
     * @return array<int,string>
     */
    public function aliases(): array;

    /**
     * 需要接收值的选项
     *
     * - 同时包含长选项名(不含 `--`)与短选项字母(不含 `-`)
     *
     * @access public
     * @return array<int,string>
     */
    public function valueOptions(): array;

    /**
     * 布尔选项
     *
     * - 用于命令行拒绝未知选项
     *
     * @access public
     * @return array<int,string>
     */
    public function booleanOptions(): array;

    /**
     * 执行命令
     *
     * @access public
     * @param Input $input 已解析参数
     * @param Output $output 输出
     * @return int 退出码(0 = 成功)
     */
    public function execute(Input $input,Output $output): int;

}
