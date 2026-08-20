<?php

namespace base\Database\Sql\Dialect;

use base\Database\Exception\CompilerException;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function str_replace;

/**
 * MySQL 方言
 */
final class MysqlDialect implements DialectInterface, InlineQuotingInterface {

    /**
     * 获取方言名称
     *
     * @access public
     * @return string
     */
    public function getName(): string {
        return 'mysql';
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
     * 包装标识符(使用反引号)
     *
     * @access public
     * @param string $identifier 标识符名称
     * @return string 包装后的标识符
     */
    public function wrapIdentifier(string $identifier): string {
        return '`'.str_replace('`','``',$identifier).'`';
    }

    /**
     * 获取占位符
     *
     * @access public
     * @param int $index 占位符索引
     * @return string 占位符
     */
    public function getPlaceholder(int $index): string {
        return '?';
    }

    /**
     * 编译 LIMIT OFFSET 子句
     *
     * - 仅设置偏移量时使用 MySQL 允许的最大限制值
     *
     * @access public
     * @param int|null $limit 限制数量
     * @param int|null $offset 偏移量
     * @return string 编译后的子句
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
     * - MySQL 标识符规则: 字母/下划线开头, 可包含字母数字下划线
     *
     * @access public
     * @param string $identifier 标识符名称
     * @return bool 是否合法
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
        return $params['type']
            .':host='.$params['host']
            .';dbname='.$params['dbname']
            .';port='.$params['port']
            .';charset='.$params['charset'];
    }

    /**
     * 将值转义为 SQL 字面量(供内联参数模式使用)
     *
     * @access public
     * @param mixed $value 值
     * @return string 转义后的字面量
     * @throws CompilerException
     */
    public function quoteValue(mixed $value): string {
        if($value===null)
            return 'NULL';
        if(is_bool($value))
            return $value?'1':'0';
        if(is_int($value)||is_float($value))
            return (string)$value;
        if(is_string($value))
            return "'".str_replace(array('\\',"'"),array('\\\\',"\\'"),$value)."'";
        throw new CompilerException('Unsupported value type for quoting.',100508,array(
            'type'=>get_debug_type($value)
        ));
    }

}
