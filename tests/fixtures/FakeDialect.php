<?php

namespace Tests\Fixtures;

use base\Database\Sql\Dialect\DialectCapabilitiesInterface;
use base\Database\Sql\Dialect\DialectInterface;
use base\Database\Sql\Dialect\MysqlCapabilities;

use function preg_match;

/**
 * 测试用假方言
 *
 * - 仅实现 DialectInterface, 不具备内联参数转义能力
 * - 使用 $n 风格占位符, 用于验证编译器占位符跟随方言而非硬编码
 */
final class FakeDialect implements DialectInterface {

    /**
     * 获取方言名称
     *
     * @access public
     * @return string
     */
    public function getName(): string {
        return 'fake';
    }

    /**
     * 获取方言能力
     *
     * @access public
     * @return DialectCapabilitiesInterface
     */
    public function getCapabilities(): DialectCapabilitiesInterface {
        return new MysqlCapabilities();
    }

    /**
     * 包装标识符
     *
     * @access public
     * @param string $identifier 标识符名称
     * @return string
     */
    public function wrapIdentifier(string $identifier): string {
        return '`'.$identifier.'`';
    }

    /**
     * 获取占位符($n 风格)
     *
     * @access public
     * @param int $index 占位符索引
     * @return string
     */
    public function getPlaceholder(int $index): string {
        return '$'.$index;
    }

    /**
     * 编译 LIMIT OFFSET 子句
     *
     * @access public
     * @param int|null $limit 限制数量
     * @param int|null $offset 偏移量
     * @return string
     */
    public function compileLimitOffset(?int $limit,?int $offset): string {
        if($limit===null&&$offset===null)
            return '';
        $limit_value=$limit??'18446744073709551615';
        if($offset!==null)
            return ' LIMIT '.$limit_value.' OFFSET '.$offset;
        return ' LIMIT '.$limit_value;
    }

    /**
     * 获取当前时间表达式
     *
     * @access public
     * @return string
     */
    public function nowExpression(): string {
        return 'NOW()';
    }

    /**
     * 校验标识符名称是否合法
     *
     * @access public
     * @param string $identifier 标识符名称
     * @return bool
     */
    public function isValidIdentifier(string $identifier): bool {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$identifier)===1;
    }

    /**
     * 构建 PDO DSN
     *
     * @access public
     * @param array $params 连接参数
     * @return string
     */
    public function buildDsn(array $params): string {
        return 'fake:'.$params['dbname'];
    }

}
