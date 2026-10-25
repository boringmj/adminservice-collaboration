<?php

namespace base\Database\Execution;

use PDO;
use PDOException;
use PDOStatement;
use base\Database\Result\AbstractCollection;
use base\Database\Result\Result;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

use function count;
use function is_bool;
use function is_int;
use function preg_match;
use function str_starts_with;
use function strcasecmp;

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
            $this->bindParameters($stmt,$params);
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
     * 绑定查询参数
     *
     * - 位置参数统一转成 1-based
     * - 命名参数支持 `:name` 和 `name` 两种键
     * - 不把命名参数和位置参数混在一起绑定, 避免 PDO 静默解析成错误值
     *
     * @access private
     * @param PDOStatement $stmt 预处理语句
     * @param array<mixed> $params 参数
     * @return void
     * @throws PDOException 命名参数与位置参数混用
     */
    private function bindParameters(PDOStatement $stmt,array $params): void {
        $hasNamed=false;
        $hasPositional=false;
        foreach($params as $key=>$value) {
            if(is_int($key))
                $hasPositional=true;
            else
                $hasNamed=true;
        }
        if($hasNamed&&$hasPositional)
            throw new PDOException('Cannot mix named and positional parameters.');
        foreach($params as $key=>$value) {
            $param=is_int($key)
                ? $key+1
                : (str_starts_with($key,':')?$key:':'.$key);
            // 布尔值用 PARAM_BOOL 绑定: 否则按字符串处理, false 会变成空字符串
            // null 值用 PARAM_NULL 绑定: 原生预处理下 null 会变成空字符串
            if(is_bool($value))
                $stmt->bindValue($param,$value,PDO::PARAM_BOOL);
            elseif($value===null)
                $stmt->bindValue($param,null,PDO::PARAM_NULL);
            else
                $stmt->bindValue($param,$value);
        }
    }

    /**
     * 判断语句是否为插入(跳过前导空白/注释再判断首关键字)
     *
     * @access private
     * @param string $sql SQL
     * @return bool
     */
    private function isInsert(string $sql): bool {
        if(preg_match('/^\s*(?:\/\*[\s\S]*?\*\/|--[^\n]*\n|\s+)*([A-Za-z]+)/',$sql,$m)===1)
            return strcasecmp($m[1],'INSERT')===0;
        return false;
    }

}
