<?php

namespace base\Database\Sql\Compiler;

use base\Database\Exception\CompilerException;
use base\Database\Sql\Dialect\DialectInterface;
use base\Database\Sql\Dialect\InlineQuotingInterface;

/**
 * SQL 写入器
 *
 * - 内部工具: 负责拼接 SQL 并收集查询参数
 * - 内联参数模式下 param() 直接输出方言转义后的字面量而不收集参数
 */
final class SqlWriter {

    /**
     * 方言
     * @var DialectInterface
     */
    private DialectInterface $dialect;

    /**
     * 是否内联参数
     * @var bool
     */
    private bool $inline;

    /**
     * SQL 片段
     * @var string
     */
    private string $sql='';

    /**
     * 查询参数
     * @var array<mixed>
     */
    private array $params=array();

    /**
     * 占位符索引
     * @var int
     */
    private int $index=1;

    /**
     * 逻辑表名 → 实际 SQL 表名映射(限定字段编译用, 由编译器按语句构建)
     * @var array<string,string>
     */
    private array $tableMap=array();

    /**
     * 构造方法
     *
     * @access public
     * @param DialectInterface $dialect 方言
     * @param bool $inline 是否内联参数
     */
    public function __construct(DialectInterface $dialect,bool $inline=false) {
        $this->dialect=$dialect;
        $this->inline=$inline;
    }

    /**
     * 追加 SQL 片段
     *
     * @access public
     * @param string $chunk SQL 片段
     * @return void
     */
    public function append(string $chunk): void {
        $this->sql.=$chunk;
    }

    /**
     * 写入参数
     *
     * - 普通模式: 记录参数并返回占位符
     * - 内联模式: 返回方言转义后的字面量(不记录参数)
     *
     * @access public
     * @param mixed $value 参数值
     * @return string
     */
    public function param(mixed $value): string {
        if($this->inline) {
            if(!($this->dialect instanceof InlineQuotingInterface))
                throw new CompilerException('Dialect does not support inline param.',100508,array(
                    'dialect'=>$this->dialect->getName()
                ));
            return $this->dialect->quoteValue($value);
        }
        $placeholder=$this->dialect->getPlaceholder($this->index);
        $this->params[]=$value;
        $this->index++;
        return $placeholder;
    }

    /**
     * 获取 SQL
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
     * 获取占位符索引
     *
     * @access public
     * @return int
     */
    public function getIndex(): int {
        return $this->index;
    }

    /**
     * 设置逻辑表名映射
     *
     * @access public
     * @param array $tableMap 逻辑表名 → 实际 SQL 表名
     * @return void
     */
    public function setTableMap(array $tableMap): void {
        $this->tableMap=$tableMap;
    }

    /**
     * 获取逻辑表名映射
     *
     * @access public
     * @return array
     */
    public function getTableMap(): array {
        return $this->tableMap;
    }

}
