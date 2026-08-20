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
            foreach($params as $i=>$value)
                $stmt->bindValue($i+1,$value);
            $stmt->execute();
            if($this->isSelect($sql)) {
                $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return new Result(true,$sql,$params,new AbstractCollection($rows),'',count($rows));
            }
            $affected=(int)$stmt->rowCount();
            $stmt->closeCursor();
            return new Result(true,$sql,$params,new AbstractCollection(),'',$affected);
        } catch(PDOException $e) {
            return new Result(false,$sql,$params,new AbstractCollection(),$e->getMessage(),0);
        }
    }

    /**
     * 判断语句是否为查询
     *
     * @access private
     * @param string $sql SQL
     * @return bool
     */
    private function isSelect(string $sql): bool {
        return strncasecmp(ltrim($sql),'SELECT',6)===0;
    }

}
