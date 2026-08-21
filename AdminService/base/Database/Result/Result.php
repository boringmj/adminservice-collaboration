<?php

namespace base\Database\Result;

/**
 * 查询结果
 *
 * - 不可变值对象, 由执行器产出
 * - 执行失败时 success 为 false 且 error 携带错误信息
 */
final class Result implements ResultInterface {

    /**
     * 是否执行成功
     * @var bool
     */
    private bool $success;

    /**
     * 执行的 SQL
     * @var string
     */
    private string $sql;

    /**
     * 查询参数
     * @var array<mixed>
     */
    private array $params;

    /**
     * 查询结果集
     * @var AbstractCollection
     */
    private AbstractCollection $results;

    /**
     * 错误信息
     * @var string
     */
    private string $error;

    /**
     * 受影响的行数
     * @var int
     */
    private int $affectedRows;

    /**
     * 最后插入的 ID(INSERT 语句)
     * @var string|null
     */
    private ?string $lastInsertId;

    /**
     * 构造方法
     *
     * @access public
     * @param bool $success 是否执行成功
     * @param string $sql 执行的 SQL
     * @param array<mixed> $params 查询参数
     * @param AbstractCollection|null $results 查询结果集
     * @param string $error 错误信息
     * @param int $affectedRows 受影响的行数
     * @param string|null $lastInsertId 最后插入的 ID
     */
    public function __construct(
        bool $success,
        string $sql,
        array $params=array(),
        ?AbstractCollection $results=null,
        string $error='',
        int $affectedRows=0,
        ?string $lastInsertId=null
    ) {
        $this->success=$success;
        $this->sql=$sql;
        $this->params=$params;
        $this->results=$results??new AbstractCollection();
        $this->error=$error;
        $this->affectedRows=$affectedRows;
        $this->lastInsertId=$lastInsertId;
    }

    /**
     * 判断是否执行成功
     *
     * @access public
     * @return bool
     */
    public function isSuccess(): bool {
        return $this->success;
    }

    /**
     * 获取执行的 SQL
     *
     * @access public
     * @return string
     */
    public function getSql(): string {
        return $this->sql;
    }

    /**
     * 获取查询参数
     *
     * @access public
     * @return array
     */
    public function getParams(): array {
        return $this->params;
    }

    /**
     * 获取查询结果集
     *
     * @access public
     * @return AbstractCollection
     */
    public function getResults(): AbstractCollection {
        return $this->results;
    }

    /**
     * 获取错误信息
     *
     * @access public
     * @return string
     */
    public function getError(): string {
        return $this->error;
    }

    /**
     * 获取受影响的行数
     *
     * @access public
     * @return int
     */
    public function getAffectedRows(): int {
        return $this->affectedRows;
    }

    /**
     * 获取最后插入的 ID(仅 INSERT 语句)
     *
     * @access public
     * @return string|null
     */
    public function getLastInsertId(): ?string {
        return $this->lastInsertId;
    }

}
