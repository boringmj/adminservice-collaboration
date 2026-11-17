<?php

namespace base\Console;

/**
 * 命令行输入契约
 *
 * - 命令实现只依赖本接口, 不依赖具体的参数解析类
 *
 * @access public
 * @package base\Console
 * @version 1.0.0
 */
interface InputInterface {

    /**
     * 全部位置参数
     *
     * @access public
     * @return array<int,string>
     */
    public function arguments(): array;

    /**
     * 取指定位置参数
     *
     * @access public
     * @param int $index 下标
     * @param string|null $default 默认值
     * @return string|null
     */
    public function argument(int $index,?string $default=null): ?string;

    /**
     * 全部选项
     *
     * @access public
     * @return array<string,string|bool>
     */
    public function options(): array;

    /**
     * 取选项值
     *
     * @access public
     * @param string $name 长选项名
     * @param string|null $shortcut 短选项字母
     * @param string|bool|null $default 默认值
     * @return string|bool|null
     */
    public function option(string $name,?string $shortcut=null,string|bool|null $default=null): string|bool|null;

    /**
     * 是否存在选项
     *
     * @access public
     * @param string $name 长选项名
     * @param string|null $shortcut 短选项字母
     * @return bool
     */
    public function hasOption(string $name,?string $shortcut=null): bool;

    /**
     * `--` 之后的原始内容
     *
     * @access public
     * @return array<int,string>
     */
    public function passthrough(): array;

}
