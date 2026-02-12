<?php

namespace base\Database\Sql\Dialect;

/**
 * 方言接口
 */
interface DialectInterface {

    /**
     * 获取方言名称
     * @return string
     */
    public function getName(): string;

    /**
     * 获取方言能力
     * @return DialectCapabilitiesInterface
     */
    public function getCapabilities(): DialectCapabilitiesInterface;

    /**
     * 包装标识符
     * @param string $identifier 标识符名称
     * @return string 包装后的标识符
     */
    public function wrapIdentifier(string $identifier): string;

    /**
     * 获取占位符
     * @param int $index 占位符索引
     * @return string 占位符
     */
    public function getPlaceholder(int $index): string;

    /**
     * 编译LIMIT OFFSET子句
     * @param ?int $limit 限制数量
     * @param ?int $offset 偏移量
     * @return string 编译后的子句
     */
    public function compileLimitOffset(?int $limit,?int $offset): string;

    /**
     * 获取当前时间表达式
     * @return string
     */
    public function nowExpression(): string;

}
