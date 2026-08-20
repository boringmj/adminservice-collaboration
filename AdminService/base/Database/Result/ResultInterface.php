<?php

namespace base\Database\Result;

/**
 * 数据库查询结果接口
 */
interface ResultInterface {

    /**
     * 判断是否执行成功
     * @return bool
     */
    public function isSuccess(): bool;

    /**
     * 获取查询使用的SQL
     * @return string
     */
    public function getSql(): string;

    /**
     * 获取查询使用的参数
     * @return array
     */
    public function getParams(): array;

    /**
     * 获取查询的结果
     * @return AbstractCollection
     */
    public function getResults(): AbstractCollection;

    /**
     * 获取查询错误信息
     * @return string
     */
    public function getError(): string;

    /**
     * 获取受影响的行数
     * @return int
     */
    public function getAffectedRows(): int;

    /**
     * 获取最后插入的 ID(仅 INSERT 语句)
     * @return string|null
     */
    public function getLastInsertId(): ?string;

}