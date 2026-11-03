<?php

namespace AdminService\Console;

use function array_map;
use function fwrite;
use function function_exists;
use function implode;
use function is_array;
use function is_resource;
use function rewind;
use function str_repeat;
use function strlen;
use function stream_get_contents;

/**
 * 命令行输出
 *
 * - 默认写 `STDOUT` / `STDERR`
 * - `buffer()` 创建内存输出实例, 测试通过 `stdout()` / `stderr()` 取文本
 * - 不输出 ANSI 颜色, 保证 Windows / Linux / CI 可移植
 *
 * @access public
 * @package AdminService\Console
 * @version 1.0.0
 */
final class Output {

    /**
     * 标准输出流
     * @var resource
     */
    private $stdout;

    /**
     * 标准错误流
     * @var resource
     */
    private $stderr;

    /**
     * 构造方法
     *
     * @access public
     * @param resource|null $stdout 标准输出流
     * @param resource|null $stderr 标准错误流
     */
    public function __construct($stdout=null,$stderr=null) {
        $this->stdout=is_resource($stdout)?$stdout:(defined('STDOUT')?STDOUT:fopen('php://stdout','w'));
        $this->stderr=is_resource($stderr)?$stderr:(defined('STDERR')?STDERR:fopen('php://stderr','w'));
    }

    /**
     * 创建内存输出实例
     *
     * @access public
     * @return self
     */
    public static function buffer(): self {
        return new self(fopen('php://memory','w+'),fopen('php://memory','w+'));
    }

    /**
     * 写文本
     *
     * @access public
     * @param string $text 文本
     * @param bool $error 是否写标准错误
     * @return void
     */
    public function write(string $text,bool $error=false): void {
        fwrite($error?$this->stderr:$this->stdout,$text);
    }

    /**
     * 写一行
     *
     * @access public
     * @param string $text 文本
     * @param bool $error 是否写标准错误
     * @return void
     */
    public function writeln(string $text='',bool $error=false): void {
        $this->write($text.PHP_EOL,$error);
    }

    /**
     * 普通信息
     *
     * @access public
     * @param string $text 文本
     * @return void
     */
    public function info(string $text): void {
        $this->writeln($text);
    }

    /**
     * 警告
     *
     * @access public
     * @param string $text 文本
     * @return void
     */
    public function warn(string $text): void {
        $this->writeln($text,true);
    }

    /**
     * 错误
     *
     * @access public
     * @param string $text 文本
     * @return void
     */
    public function error(string $text): void {
        $this->writeln($text,true);
    }

    /**
     * 输出表格
     *
     * @access public
     * @param array<int,string> $headers 表头
     * @param array<int,array<int,string>> $rows 行
     * @return void
     */
    public function table(array $headers,array $rows): void {
        $all=array_merge(array($headers),$rows);
        $widths=array();
        foreach($all as $row) {
            if(!is_array($row))
                continue;
            foreach($row as $index=>$value) {
                $width=$this->textWidth((string)$value);
                if(!isset($widths[$index])||$width>$widths[$index])
                    $widths[$index]=$width;
            }
        }
        $line='+';
        foreach($widths as $width)
            $line.=str_repeat('-',$width+2).'+';
        $this->writeln($line);
        $this->tableRow($headers,$widths);
        $this->writeln($line);
        foreach($rows as $row)
            $this->tableRow($row,$widths);
        $this->writeln($line);
    }

    /**
     * 输出表格行
     *
     * @access private
     * @param array<int,string> $row 行
     * @param array<int,int> $widths 列宽
     * @return void
     */
    private function tableRow(array $row,array $widths): void {
        $cells=array();
        foreach($widths as $index=>$width) {
            $value=(string)($row[$index]??'');
            $cells[]=' '.$value.str_repeat(' ',max(0,$width-$this->textWidth($value))).' ';
        }
        $this->writeln('|'.implode('|',$cells).'|');
    }

    /**
     * 计算显示宽度
     *
     * - 有 mbstring 时按字符显示宽度计算, 让中文表格对齐
     * - 无 mbstring 时回落字节长度
     *
     * @access private
     * @param string $text 文本
     * @return int
     */
    private function textWidth(string $text): int {
        return function_exists('mb_strwidth')?mb_strwidth($text,'UTF-8'):strlen($text);
    }

    /**
     * 读取缓冲标准输出
     *
     * @access public
     * @return string
     */
    public function stdout(): string {
        if(!is_resource($this->stdout))
            return '';
        rewind($this->stdout);
        $content=stream_get_contents($this->stdout);
        return is_string($content)?$content:'';
    }

    /**
     * 读取缓冲标准错误
     *
     * @access public
     * @return string
     */
    public function stderr(): string {
        if(!is_resource($this->stderr))
            return '';
        rewind($this->stderr);
        $content=stream_get_contents($this->stderr);
        return is_string($content)?$content:'';
    }

}
