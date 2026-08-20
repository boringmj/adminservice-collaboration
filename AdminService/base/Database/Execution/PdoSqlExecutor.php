<?php

namespace base\Database\Execution;

use PDO;
use PDOException;
use base\Database\Result\AbstractCollection;
use base\Database\Result\Result;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

use function count;
use function ltrim;
use function strncasecmp;

/**
 * PDO SQL 执行器
 *
 * - 执行编译后的语句并产出结果
 * - SELECT 语句返回行集合, 其余语句返回受影响行数
 * - PDO 执行错误转为失败的结果而非抛异常, 由上层决定处理方式
 */
final class PdoSqlExecutor implements SqlExecutorInterface {

    /**
     * 裸连接对象
     * @var PDO
     */
    private PDO $connection;

    /**
     * 构造方法
     *
     * @access public
     * @param PDO $connection 裸连接对象
     */
    public function __construct(PDO $connection) {
        $this->connection=$connection;
    }

    /**
     * 执行语句
     *
     * @access public
     * @param CompiledStatementInterface $statement 编译后的语句
     * @return ResultInterface
     */
    public function execute(CompiledStatementInterface $statement): ResultInterface {
        $sql=$statement->getSql();
        $params=$statement->getParams();
        try {
            $stmt=$this->connection->prepare($sql);
            foreach($params as $i=>$value) {
                // null 值必须用 PARAM_NULL 绑定, 否则原生预处理下会变成空字符串
                if($value===null)
                    $stmt->bindValue($i+1,null,PDO::PARAM_NULL);
                else
                    $stmt->bindValue($i+1,$value);
            }
            $stmt->execute();
            // 以结果集列数判断是否返回数据(比文本前缀准确, 覆盖 CTE 等场景)
            if($stmt->columnCount()>0) {
                $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return new Result(true,$sql,$params,new AbstractCollection($rows),'',count($rows));
            }
            $affected=(int)$stmt->rowCount();
            $stmt->closeCursor();
            // INSERT 语句捕获自增主键
            $lastInsertId=$this->isInsert($sql)
                ? $this->connection->lastInsertId()
                : null;
            return new Result(true,$sql,$params,new AbstractCollection(),'',$affected,$lastInsertId);
        } catch(PDOException $e) {
            return new Result(false,$sql,$params,new AbstractCollection(),$e->getMessage(),0);
        }
    }

    /**
     * 判断语句是否为插入
     *
     * @access private
     * @param string $sql SQL
     * @return bool
     */
    private function isInsert(string $sql): bool {
        return strncasecmp(ltrim($sql),'INSERT',6)===0;
    }

}
