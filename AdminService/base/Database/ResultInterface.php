<?php

namespace base\Database;

use base\Database\Result\AbstractCollection;

/**
 * 数据库查询结果接口
 */
interface ResultInterface {

    /**
     * 判断是否执行成功
     * 
     * @access public
     * @return bool
     */
    public function isSuccess(): bool;

    /**
     * 获取查询使用的SQL
     * 
     * @access public
     * @return string
     */
    public function getSql(): string;

    /**
     * 获取查询使用的参数
     * 
     * @access public
     * @return array
     */
    public function getParams(): array;

    /**
     * 获取查询的结果
     * 
     * @access public
     * @return AbstractCollection
     */
    public function getResults(): AbstractCollection;

    /**
     * 获取查询错误信息
     * 
     * @access public
     * @return string
     */
    public function getError(): string;

    /**
     * 获取受影响的行数
     * 
     * @access public
     * @return int
     */
    public function getAffectedRows(): int;

}