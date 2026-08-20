<?php

namespace base\Database\Sql\Builder;

use base\Database\Exception\QueryException;
use base\Database\Query\Query;
use base\Database\Query\QueryInterface;
use base\Database\Sql\Definition\StatementDefinition;
use base\Database\Sql\Definition\StatementDefinitionInterface;
use base\Database\Type\StatementType;

use function in_array;

/**
 * 查询语句构建器
 *
 * - 将查询对象快照为不可变的语句定义
 * - 负责语义层面的校验: 语句类型、主表、更新/删除条件、写入数据
 */
final class QueryStatementBuilder implements StatementBuilderInterface {

    /**
     * 构建语句定义
     *
     * @access public
     * @param QueryInterface $query 查询对象
     * @return StatementDefinitionInterface
     * @throws QueryException
     */
    public function build(QueryInterface $query): StatementDefinitionInterface {
        if(!$query instanceof Query)
            throw new QueryException('Unsupported query object.',100701,array(
                'query'=>get_debug_type($query)
            ));
        $type=$query->getType();
        // 校验语句类型
        if(!in_array($type,array(
            StatementType::SELECT,
            StatementType::FIND,
            StatementType::COUNT,
            StatementType::INSERT,
            StatementType::UPDATE,
            StatementType::DELETE
        ),true))
            throw new QueryException('Invalid statement type.',100701,array(
                'type'=>$type
            ));
        // 校验主表
        if($query->getTable()===null)
            throw new QueryException('Table not set.',100702);
        // 更新/删除必须带查询条件
        if(($type===StatementType::UPDATE||$type===StatementType::DELETE)&&!$query->hasWhere())
            throw new QueryException('Update/Delete must have where condition.',100703);
        // 插入必须携带数据
        if($type===StatementType::INSERT&&empty($query->getRows()))
            throw new QueryException('Insert requires data.',100704);
        // 更新必须携带数据
        if($type===StatementType::UPDATE&&empty($query->getSets()))
            throw new QueryException('Update requires data.',100705);
        return new StatementDefinition(
            $type,
            $query->getTable(),
            $query->getColumns(),
            $query->isDistinct(),
            $query->getJoins(),
            $query->getWhere(),
            $query->getGroup(),
            $query->getOrder(),
            $query->getLimit(),
            $query->getOffset(),
            $query->getLock(),
            $query->getRows(),
            $query->getSets()
        );
    }

}
