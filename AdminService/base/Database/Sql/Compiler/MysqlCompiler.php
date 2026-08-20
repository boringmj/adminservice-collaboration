<?php

namespace base\Database\Sql\Compiler;

use base\Database\Exception\CompilerException;
use base\Database\Exception\QueryException;
use base\Database\Sql\Definition\Field;
use base\Database\Sql\Definition\Literal;
use base\Database\Sql\Definition\StatementDefinition;
use base\Database\Sql\Definition\StatementDefinitionInterface;
use base\Database\Sql\Definition\Table;
use base\Database\Sql\Definition\Where;
use base\Database\Sql\Type\CompileFeature;
use base\Database\Type\StatementType;

use function array_keys;
use function count;
use function implode;
use function is_array;
use function strtoupper;

/**
 * MySQL SQL 编译器
 *
 * - 将语义化的语句定义编译为 SQL 字符串与参数列表
 * - 语义对齐旧的 sql/Mysql 驱动, 但不继承 update-id-where 的怪癖
 */
final class MysqlCompiler implements SqlCompilerInterface {

    /**
     * 编译语句
     *
     * @access public
     * @param StatementDefinitionInterface $definition 语句定义
     * @param CompilerContextInterface $context 编译器上下文
     * @return CompiledStatementInterface
     * @throws CompilerException
     * @throws QueryException
     */
    public function compile(
        StatementDefinitionInterface $definition,
        CompilerContextInterface $context
    ): CompiledStatementInterface {
        if(!$definition instanceof StatementDefinition)
            throw new CompilerException('Unsupported statement definition.',100501,array(
                'definition'=>get_debug_type($definition)
            ));
        $inline=$context->hasFeature(CompileFeature::INLINE_PARAM);
        $writer=new SqlWriter($context->getDialect(),$inline);
        switch($definition->getType()) {
            case StatementType::SELECT:
                $this->compileSelect($definition,$context,$writer,false);
                break;
            case StatementType::FIND:
                $this->compileSelect($definition,$context,$writer,true);
                break;
            case StatementType::COUNT:
                $this->compileCount($definition,$context,$writer);
                break;
            case StatementType::INSERT:
                $this->compileInsert($definition,$context,$writer);
                break;
            case StatementType::UPDATE:
                $this->compileUpdate($definition,$context,$writer);
                break;
            case StatementType::DELETE:
                $this->compileDelete($definition,$context,$writer);
                break;
            default:
                throw new CompilerException('Unsupported statement type.',100501,array(
                    'type'=>$definition->getType()
                ));
        }
        return new CompiledStatement($writer->getSql(),$writer->getParams());
    }

    /**
     * 编译查询语句
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @param CompilerContextInterface $context 编译器上下文
     * @param SqlWriter $writer SQL 写入器
     * @param bool $find 是否为单条查询
     * @return void
     * @throws QueryException
     */
    private function compileSelect(
        StatementDefinition $definition,
        CompilerContextInterface $context,
        SqlWriter $writer,
        bool $find
    ): void {
        $this->requireTable($definition);
        $writer->append('SELECT ');
        if($definition->isDistinct())
            $writer->append('DISTINCT ');
        $this->writeColumns($writer,$context,$definition);
        $writer->append(' FROM '.$this->tableSql($context,$definition->getTable()));
        $this->writeJoins($writer,$context,$definition->getJoins());
        $this->writeWhere($writer,$context,$definition->getWhere());
        $this->writeGroup($writer,$context,$definition->getGroup());
        $this->writeOrder($writer,$context,$definition->getOrder());
        $limit=$definition->getLimit();
        $offset=$definition->getOffset();
        // 单条查询且未指定数量限制时强制 LIMIT 1
        if($find&&$limit===null)
            $limit=1;
        $writer->append($context->getDialect()->compileLimitOffset($limit,$offset));
        $this->writeLock($writer,$definition->getLock());
    }

    /**
     * 编译统计语句
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @param CompilerContextInterface $context 编译器上下文
     * @param SqlWriter $writer SQL 写入器
     * @return void
     * @throws QueryException
     */
    private function compileCount(
        StatementDefinition $definition,
        CompilerContextInterface $context,
        SqlWriter $writer
    ): void {
        $this->requireTable($definition);
        $columns=$definition->getColumns();
        if($definition->isDistinct()) {
            // COUNT(DISTINCT cols) 需要至少一个列
            if(empty($columns))
                throw new QueryException('COUNT(DISTINCT) requires at least one column.',100504);
            $distinct_fields=array();
            foreach($columns as $column)
                $distinct_fields[]=$this->fieldSql($context,$column[0]);
            $writer->append('SELECT COUNT(DISTINCT '.implode(', ',$distinct_fields).') AS '
                .$this->identifierSql($context,'__count','alias'));
        } else {
            // 非去重统计始终计数全部行
            $writer->append('SELECT COUNT(*) AS '.$this->identifierSql($context,'__count','alias'));
        }
        // 分组时附带分组字段
        foreach($definition->getGroup() as $field)
            $writer->append(', '.$this->fieldSql($context,$field));
        $writer->append(' FROM '.$this->tableSql($context,$definition->getTable()));
        $this->writeJoins($writer,$context,$definition->getJoins());
        $this->writeWhere($writer,$context,$definition->getWhere());
        $this->writeGroup($writer,$context,$definition->getGroup());
        $this->writeLock($writer,$definition->getLock());
    }

    /**
     * 编译插入语句
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @param CompilerContextInterface $context 编译器上下文
     * @param SqlWriter $writer SQL 写入器
     * @return void
     * @throws QueryException
     */
    private function compileInsert(
        StatementDefinition $definition,
        CompilerContextInterface $context,
        SqlWriter $writer
    ): void {
        $this->requireTable($definition);
        $this->requireNoLock($definition);
        $rows=$definition->getRows();
        if(empty($rows))
            throw new QueryException('Insert requires data.',100504);
        // 校验数据行均为数组
        foreach($rows as $row) {
            if(!is_array($row))
                throw new QueryException('Insert rows must be arrays.',100504,array(
                    'row'=>$row
                ));
        }
        // 以第一行为基准确定列
        $columns=array_keys($rows[0]);
        if(empty($columns))
            throw new QueryException('Insert requires at least one column.',100504);
        // 校验所有行的列保持一致
        foreach($rows as $row) {
            if(array_keys($row)!==$columns)
                throw new QueryException('Insert rows must have the same columns.',100516);
        }
        $writer->append('INSERT INTO '.$this->tableSql($context,$definition->getTable()).' (');
        $column_sqls=array();
        foreach($columns as $column) {
            $column_sqls[]=$this->identifierSql($context,$column,'column');
        }
        $writer->append(implode(', ',$column_sqls).') VALUES ');
        $row_sqls=array();
        foreach($rows as $row) {
            $placeholders=array();
            foreach($columns as $column)
                $placeholders[]=$writer->param($row[$column]);
            $row_sqls[]='('.implode(', ',$placeholders).')';
        }
        $writer->append(implode(', ',$row_sqls));
    }

    /**
     * 编译更新语句
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @param CompilerContextInterface $context 编译器上下文
     * @param SqlWriter $writer SQL 写入器
     * @return void
     * @throws QueryException
     */
    private function compileUpdate(
        StatementDefinition $definition,
        CompilerContextInterface $context,
        SqlWriter $writer
    ): void {
        $this->requireTable($definition);
        $this->requireNoLock($definition);
        $sets=$definition->getSets();
        if(empty($sets))
            throw new QueryException('Update requires data.',100505);
        $this->requireWhere($definition,'Update must have where condition.');
        $writer->append('UPDATE '.$this->tableSql($context,$definition->getTable()).' SET ');
        $set_sqls=array();
        foreach($sets as $column=>$value) {
            $set_sqls[]=$this->identifierSql($context,$column,'column').' = '.$writer->param($value);
        }
        $writer->append(implode(', ',$set_sqls));
        $this->writeWhere($writer,$context,$definition->getWhere());
    }

    /**
     * 编译删除语句
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @param CompilerContextInterface $context 编译器上下文
     * @param SqlWriter $writer SQL 写入器
     * @return void
     * @throws QueryException
     */
    private function compileDelete(
        StatementDefinition $definition,
        CompilerContextInterface $context,
        SqlWriter $writer
    ): void {
        $this->requireTable($definition);
        $this->requireNoLock($definition);
        $this->requireWhere($definition,'Delete must have where condition.');
        $writer->append('DELETE FROM '.$this->tableSql($context,$definition->getTable()));
        $this->writeWhere($writer,$context,$definition->getWhere());
    }

    /**
     * 写入查询字段列表
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param StatementDefinition $definition 语句定义
     * @return void
     */
    private function writeColumns(
        SqlWriter $writer,
        CompilerContextInterface $context,
        StatementDefinition $definition
    ): void {
        $columns=$definition->getColumns();
        if(empty($columns)) {
            $writer->append('*');
            return;
        }
        $parts=array();
        foreach($columns as $column) {
            $sql=$this->fieldSql($context,$column[0]);
            if($column[1]!==null)
                $sql.=' AS '.$this->identifierSql($context,$column[1],'alias');
            $parts[]=$sql;
        }
        $writer->append(implode(', ',$parts));
    }

    /**
     * 写入关联查询
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param array $joins 关联查询列表
     * @return void
     */
    private function writeJoins(
        SqlWriter $writer,
        CompilerContextInterface $context,
        array $joins
    ): void {
        foreach($joins as $join) {
            $writer->append(' '.$join->getKeyword().' '.$this->tableSql($context,$join->getTable()));
            $writer->append(' ON ');
            $conditions=$join->getConditions();
            $count=count($conditions);
            foreach($conditions as $i=>$condition) {
                $writer->append($this->fieldSql($context,$condition[0]));
                $writer->append(' '.strtoupper($condition[1]).' ');
                // 右值为字段引用则写标识符, Literal/标量则作为参数绑定
                if($condition[2] instanceof Field)
                    $writer->append($this->fieldSql($context,$condition[2]));
                else if($condition[2] instanceof Literal)
                    $writer->append($writer->param($condition[2]->getValue()));
                else
                    $writer->append($writer->param($condition[2]));
                if($i<$count-1)
                    $writer->append(' AND ');
            }
        }
    }

    /**
     * 写入查询条件
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param Where|null $where 条件根节点
     * @return void
     * @throws QueryException
     */
    private function writeWhere(
        SqlWriter $writer,
        CompilerContextInterface $context,
        ?Where $where
    ): void {
        if($where===null||$this->isWhereEmpty($where))
            return;
        $writer->append(' WHERE ');
        // 根节点不包裹括号, 仅嵌套分组需要括号
        if($where->isGroup())
            $this->writeWhereGroupContent($writer,$context,$where);
        else
            $this->writeWhereLeaf($writer,$context,$where);
    }

    /**
     * 递归写入条件节点(带括号包裹)
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param Where $where 条件节点
     * @return void
     * @throws QueryException
     */
    private function writeWhereNode(
        SqlWriter $writer,
        CompilerContextInterface $context,
        Where $where
    ): void {
        if($where->isGroup()) {
            $writer->append('(');
            $this->writeWhereGroupContent($writer,$context,$where);
            $writer->append(')');
            return;
        }
        $this->writeWhereLeaf($writer,$context,$where);
    }

    /**
     * 写入分组内容(不含括号)
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param Where $where 分组条件
     * @return void
     * @throws QueryException
     */
    private function writeWhereGroupContent(
        SqlWriter $writer,
        CompilerContextInterface $context,
        Where $where
    ): void {
        // 过滤空分组
        $filtered=array();
        foreach($where->getConditions() as $condition) {
            if($this->isWhereEmpty($condition))
                continue;
            $filtered[]=$condition;
        }
        if(empty($filtered))
            return;
        $first=true;
        foreach($filtered as $condition) {
            if(!$first)
                $writer->append(' '.$where->getConnector().' ');
            $first=false;
            $this->writeWhereNode($writer,$context,$condition);
        }
    }

    /**
     * 写入叶子条件
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param Where $where 叶子条件
     * @return void
     * @throws QueryException
     */
    private function writeWhereLeaf(
        SqlWriter $writer,
        CompilerContextInterface $context,
        Where $where
    ): void {
        $writer->append($this->fieldSql($context,$where->getField()));
        $operator=$where->getOperator();
        switch($operator) {
            case 'IS NULL':
            case 'IS NOT NULL':
                $writer->append(' '.$operator);
                return;
            case 'IN':
            case 'NOT IN':
                $values=$where->getValue();
                if(!is_array($values)||empty($values))
                    throw new QueryException('IN requires a non-empty array value.',100509);
                $writer->append(' '.$operator.' (');
                $placeholders=array();
                foreach($values as $value)
                    $placeholders[]=$writer->param($value);
                $writer->append(implode(', ',$placeholders).')');
                return;
            case 'BETWEEN':
            case 'NOT BETWEEN':
                $values=$where->getValue();
                if(!is_array($values)||count($values)!==2)
                    throw new QueryException('BETWEEN requires two values.',100510);
                $writer->append(' '.$operator.' '
                    .$writer->param($values[0])
                    .' AND '.$writer->param($values[1]));
                return;
            default:
                $writer->append(' '.$operator.' '.$writer->param($where->getValue()));
                return;
        }
    }

    /**
     * 写入分组子句
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param array $group 分组字段列表
     * @return void
     */
    private function writeGroup(
        SqlWriter $writer,
        CompilerContextInterface $context,
        array $group
    ): void {
        if(empty($group))
            return;
        $parts=array();
        foreach($group as $field)
            $parts[]=$this->fieldSql($context,$field);
        $writer->append(' GROUP BY '.implode(', ',$parts));
    }

    /**
     * 写入排序子句
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param CompilerContextInterface $context 编译器上下文
     * @param array $order 排序字段列表
     * @return void
     */
    private function writeOrder(
        SqlWriter $writer,
        CompilerContextInterface $context,
        array $order
    ): void {
        if(empty($order))
            return;
        $parts=array();
        foreach($order as $item)
            $parts[]=$this->fieldSql($context,$item[0]).' '.$item[1];
        $writer->append(' ORDER BY '.implode(', ',$parts));
    }

    /**
     * 写入行锁子句
     *
     * @access private
     * @param SqlWriter $writer SQL 写入器
     * @param string|null $lock 锁类型
     * @return void
     */
    private function writeLock(SqlWriter $writer,?string $lock): void {
        if($lock==='shared')
            $writer->append(' LOCK IN SHARE MODE');
        elseif($lock==='update')
            $writer->append(' FOR UPDATE');
    }

    /**
     * 编译字段引用为 SQL
     *
     * - 表前缀只作用于主表与关联表; 带表限定的字段按原样编译
     * - 使用表名限定字段时若配置了表前缀, 限定名与编译后的表名不一致, 请改用别名限定
     *
     * @access private
     * @param CompilerContextInterface $context 编译器上下文
     * @param Field $field 字段引用
     * @return string
     */
    private function fieldSql(CompilerContextInterface $context,Field $field): string {
        if($field->isQualified())
            return $this->identifierSql($context,$field->getTable(),'table')
                .'.'.$this->identifierSql($context,$field->getColumn(),'column');
        return $this->identifierSql($context,$field->getColumn(),'column');
    }

    /**
     * 编译表引用为 SQL(自动附加表前缀)
     *
     * @access private
     * @param CompilerContextInterface $context 编译器上下文
     * @param Table $table 表引用
     * @return string
     */
    private function tableSql(CompilerContextInterface $context,Table $table): string {
        $sql=$this->identifierSql($context,$context->getTablePrefix().$table->getName(),'table');
        if($table->hasAlias())
            $sql.=' '.$this->identifierSql($context,$table->getAlias(),'alias');
        return $sql;
    }

    /**
     * 编译标识符为 SQL(应用命名策略后包裹)
     *
     * @access private
     * @param CompilerContextInterface $context 编译器上下文
     * @param string $name 标识符名称
     * @param string $kind 标识符类型(table/column/alias)
     * @return string
     */
    private function identifierSql(CompilerContextInterface $context,string $name,string $kind): string {
        $naming=$context->getNamingStrategy();
        switch($kind) {
            case 'table':
                $name=$naming->table($name);
                break;
            case 'column':
                $name=$naming->column($name);
                break;
            case 'alias':
                $name=$naming->alias($name);
                break;
        }
        // 标识符合法规则由方言定义
        if(!$context->getDialect()->isValidIdentifier($name))
            throw new QueryException('Invalid identifier name.',100517,array(
                'kind'=>$kind,
                'name'=>$name
            ));
        return $context->getDialect()->wrapIdentifier($name);
    }

    /**
     * 校验主表是否已设置
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @return void
     * @throws QueryException
     */
    private function requireTable(StatementDefinition $definition): void {
        if($definition->getTable()===null)
            throw new QueryException('Table not set.',100502);
    }

    /**
     * 校验行锁仅允许用于读语句(MySQL 的写语句不支持锁子句)
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @return void
     * @throws QueryException
     */
    private function requireNoLock(StatementDefinition $definition): void {
        if($definition->getLock()!==null)
            throw new QueryException('Row lock is not supported for this statement type.',100515,array(
                'type'=>$definition->getType()
            ));
    }

    /**
     * 校验查询条件是否存在(更新/删除必须带条件)
     *
     * @access private
     * @param StatementDefinition $definition 语句定义
     * @param string $message 错误信息
     * @return void
     * @throws QueryException
     */
    private function requireWhere(StatementDefinition $definition,string $message): void {
        $where=$definition->getWhere();
        if($where===null||$this->isWhereEmpty($where))
            throw new QueryException($message,100503);
    }

    /**
     * 递归判断条件树是否为空
     *
     * @access private
     * @param Where $where 条件节点
     * @return bool
     */
    private function isWhereEmpty(Where $where): bool {
        if(!$where->isGroup())
            return false;
        foreach($where->getConditions() as $condition) {
            if(!$this->isWhereEmpty($condition))
                return false;
        }
        return true;
    }

}
