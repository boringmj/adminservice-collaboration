<?php

namespace base\Console;

/**
 * 命令行输出契约
 *
 * - 命令实现只依赖本接口, 不依赖具体的输出实现
 *
 * @access public
 * @package base\Console
 * @version 1.0.0
 */
interface OutputInterface {

    /**
     * 写文本
     *
     * @access public
     * @param string $text 文本
     * @param bool $error 是否写标准错误
     * @return void
     */
    public function write(string $text,bool $error=false): void;

    /**
     * 写一行
     *
     * @access public
     * @param string $text 文本
     * @param bool $error 是否写标准错误
     * @return void
     */
    public function writeln(string $text='',bool $error=false): void;

    /**
     * 普通信息
     *
     * @access public
     * @param string $text 文本
     * @return void
     */
    public function info(string $text): void;

    /**
     * 警告
     *
     * @access public
     * @param string $text 文本
     * @return void
     */
    public function warn(string $text): void;

    /**
     * 错误
     *
     * @access public
     * @param string $text 文本
     * @return void
     */
    public function error(string $text): void;

    /**
     * 输出表格
     *
     * @access public
     * @param array<int,string> $headers 表头
     * @param array<int,array<int,string>> $rows 行
     * @return void
     */
    public function table(array $headers,array $rows): void;

}
