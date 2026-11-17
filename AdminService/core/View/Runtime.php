<?php

namespace AdminService\View;

use function array_key_exists;
use function count;
use function htmlspecialchars;
use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function json_encode;

/**
 * 模板运行时助手
 *
 * - 编译产物与解释器共用, 保证两条渲染路径的数据读取与转义一致
 *
 * @access public
 * @package AdminService\View
 * @version 1.0.0
 */
final class Runtime {

    /**
     * 构造函数(禁止实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 按路径读取数据
     *
     * - 数组与对象都支持; 键不存在或值为 null 时返回 null
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @param array<int,int|string> $path 路径段
     * @return mixed
     */
    public static function get(array $data,array $path): mixed {
        $value=$data;
        foreach($path as $key) {
            if(is_array($value)) {
                if(!array_key_exists($key,$value))
                    return null;
                $value=$value[$key];
                continue;
            }
            if(is_object($value)) {
                $name=(string)$key;
                if(!isset($value->$name))
                    return null;
                $value=$value->$name;
                continue;
            }
            return null;
        }
        return $value;
    }

    /**
     * 转义输出值
     *
     * @access public
     * @param mixed $value 值
     * @return string
     */
    public static function escape(mixed $value): string {
        return self::render($value,true);
    }

    /**
     * 原始输出值
     *
     * @access public
     * @param mixed $value 值
     * @return string
     */
    public static function raw(mixed $value): string {
        return self::render($value,false);
    }

    /**
     * 把值转为模板文本
     *
     * - 标量直接转字符串; 数组/对象先 JSON 编码
     *
     * @access private
     * @param mixed $value 值
     * @param bool $escape 是否转义 HTML
     * @return string
     */
    private static function render(mixed $value,bool $escape): string {
        if(is_scalar($value)) {
            $text=(string)$value;
            return $escape?htmlspecialchars($text,ENT_QUOTES,'UTF-8'):$text;
        }
        if(!is_array($value)&&!is_object($value))
            return '';
        $json=json_encode($value,JSON_UNESCAPED_UNICODE);
        if($json===false)
            return '';
        return $escape?htmlspecialchars($json,ENT_QUOTES,'UTF-8'):$json;
    }

    /**
     * 把值写入数据路径
     *
     * - 不存在的路径按数组创建
     *
     * @access public
     * @param array<string,mixed> $data 目标数据
     * @param array<int,int|string> $path 路径段
     * @param mixed $value 值
     * @return void
     */
    public static function assign(array &$data,array $path,mixed $value): void {
        if($path===array())
            return;
        $current=&$data;
        $last=count($path)-1;
        foreach($path as $index=>$key) {
            if($index===$last) {
                $current[$key]=$value;
                return;
            }
            if(!isset($current[$key])||!is_array($current[$key]))
                $current[$key]=array();
            $current=&$current[$key];
        }
    }

    /**
     * 可遍历值
     *
     * - 非数组按空集合处理, 与原模板行为一致
     *
     * @access public
     * @param mixed $value 值
     * @return array<int|string,mixed>
     */
    public static function iterable(mixed $value): array {
        return is_array($value)?$value:array();
    }

    /**
     * 条件真值
     *
     * @access public
     * @param mixed $value 值
     * @return bool
     */
    public static function truthy(mixed $value): bool {
        return (bool)$value;
    }

    /**
     * 数值化
     *
     * - 非数值按 0 处理, 避免模板里的负号作用于非数值时报错
     *
     * @access public
     * @param mixed $value 值
     * @return int|float
     */
    public static function number(mixed $value): int|float {
        return is_numeric($value)?$value+0:0;
    }

}
