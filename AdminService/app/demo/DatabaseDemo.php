<?php

namespace app\demo;

use Throwable;
use base\Database\Db;
use base\Database\Query\Query;
use base\Database\Result\ResultInterface;

/**
 * 数据库使用示例
 *
 * - 新 DBAL 完整用法指南, 基于 Query 流式构建器 + Db 门面
 * - 每个示例都会真实执行, 并在返回数组中附带生成的 SQL 与结果, 便于对照学习
 * - 运行前需在 config/database.php 配置好连接, 并准备示例数据表
 */
final class DatabaseDemo {

    /**
     * 数据库入口
     * @var Db
     */
    private Db $db;

    /**
     * 构造方法
     *
     * @access public
     * @param Db|null $db 数据库入口(默认从框架配置创建, 测试可注入)
     */
    public function __construct(?Db $db=null) {
        $this->db=$db??Db::fromConfig();
    }

    /**
     * 完整示例(返回带标签的结果集)
     *
     * @access public
     * @return array
     */
    public function test(): array {
        $data=array();
        try {
            $db=$this->db; // 默认连接; 也可 Db::fromConfig('log') 切换命名连接

            // =====================================================
            // 一、查询
            // =====================================================

            // 1. 查询全部字段
            $data['select_all']=$this->dump(
                $db->query(Query::select()->from('users'))
            );

            // 2. 查询指定字段(支持逗号分隔字符串 / 数组 / 多次调用)
            $data['select_fields']=$this->dump(
                $db->query(Query::select()->from('users')->field('id,name'))
            );
            $data['select_fields_array']=$this->dump(
                $db->query(Query::select()->from('users')->field(array('id','name')))
            );

            // 3. 字段别名与表限定
            $data['select_alias']=$this->dump(
                $db->query(Query::select()->from('users','u')
                    ->field('u.id')
                    ->field(array('u.name'=>'nickname')))
            );

            // 4. 去重
            $data['distinct']=$this->dump(
                $db->query(Query::select()->from('users')->distinct()->field('status'))
            );

            // 5. 排序(ASC/DESC)
            $data['order']=$this->dump(
                $db->query(Query::select()->from('users')->order('id','DESC'))
            );
            // 多字段排序
            $data['order_multi']=$this->dump(
                $db->query(Query::select()->from('users')
                    ->order('status','ASC')
                    ->order('id','DESC'))
            );

            // 6. 分页(LIMIT / OFFSET)
            $data['limit']=$this->dump(
                $db->query(Query::select()->from('users')->limit(10))
            );
            $data['limit_offset']=$this->dump(
                $db->query(Query::select()->from('users')->limit(10,20))
            );
            $data['offset']=$this->dump(
                $db->query(Query::select()->from('users')->offset(20))
            );

            // 7. 分组
            $data['group']=$this->dump(
                $db->query(Query::select()->from('users')->group('status'))
            );

            // =====================================================
            // 二、条件
            // =====================================================

            // 1. 比较操作符: =, >, <, >=, <=, !=, <>, LIKE, NOT LIKE
            $data['where_eq']=$this->dump(
                $db->query(Query::select()->from('users')->where('id',1))
            );
            $data['where_gt']=$this->dump(
                $db->query(Query::select()->from('users')->where('age',18,'>='))
            );
            $data['where_like']=$this->dump(
                $db->query(Query::select()->from('users')->where('name','张%','LIKE'))
            );

            // 2. IN / NOT IN
            $data['where_in']=$this->dump(
                $db->query(Query::select()->from('users')->whereIn('status',array(1,2,3)))
            );
            $data['where_not_in']=$this->dump(
                $db->query(Query::select()->from('users')->whereNotIn('status',array(4,5)))
            );

            // 3. BETWEEN / NOT BETWEEN
            $data['where_between']=$this->dump(
                $db->query(Query::select()->from('users')->whereBetween('age',18,60))
            );
            $data['where_not_between']=$this->dump(
                $db->query(Query::select()->from('users')->whereBetween('age',18,60,true))
            );

            // 4. IS NULL / IS NOT NULL
            $data['where_null']=$this->dump(
                $db->query(Query::select()->from('users')->whereNull('deleted_at'))
            );
            $data['where_not_null']=$this->dump(
                $db->query(Query::select()->from('users')->whereNull('deleted_at',true))
            );

            // 5. 链式条件(默认 AND)
            $data['where_and']=$this->dump(
                $db->query(Query::select()->from('users')
                    ->where('status',1)
                    ->where('age',18,'>='))
            );

            // 6. 复合条件(嵌套 AND / OR)
            $data['where_group']=$this->dump(
                $db->query(Query::select()->from('users')
                    ->where('status',1)
                    ->whereGroup('OR',function($q) {
                        $q->where('age',30,'<')
                          ->where('name','张%','LIKE');
                    }))
            );

            // 7. 带表限定的条件
            $data['where_qualified']=$this->dump(
                $db->query(Query::select()->from('users','u')
                    ->where('u.id',1))
            );

            // =====================================================
            // 三、单条查询
            // =====================================================

            // find 始终返回一条(自动 LIMIT 1)
            $data['find']=$this->dump(
                $db->query(Query::find()->from('users')->where('id',1))
            );

            // =====================================================
            // 四、写入
            // =====================================================

            // 1. 插入单行
            $data['insert']=$this->dump(
                $db->query(Query::insert(array(
                    'name'=>'张三',
                    'age'=>20,
                    'status'=>1,
                ))->from('users'))
            );

            // 2. 插入多行(一次 INSERT 多组 VALUES)
            $data['insert_multi']=$this->dump(
                $db->query(Query::insert(array(
                    array('name'=>'李四','age'=>21,'status'=>1),
                    array('name'=>'王五','age'=>22,'status'=>1),
                ))->from('users'))
            );

            // 3. 更新(必须带 where 条件)
            $data['update']=$this->dump(
                $db->query(Query::update(array('name'=>'张三三','age'=>21))
                    ->from('users')
                    ->where('id',1))
            );

            // 4. 删除(必须带 where 条件)
            $data['delete_where']=$this->dump(
                $db->query(Query::delete()->from('users')->where('status',0))
            );

            // 5. 按主键删除
            $data['delete_by_id']=$this->dump(
                $db->query(Query::delete()->from('users')->whereIn('id',array(10,11)))
            );

            // =====================================================
            // 五、聚合统计
            // =====================================================

            // count 返回 COUNT(*)
            $data['count']=$this->dump(
                $db->query(Query::count()->from('users'))
            );
            // 去重统计 COUNT(DISTINCT col)
            $data['count_distinct']=$this->dump(
                $db->query(Query::count()->from('users')->distinct()->field('status'))
            );
            // 分组统计
            $data['count_group']=$this->dump(
                $db->query(Query::count()->from('users')->group('status'))
            );

            // =====================================================
            // 六、关联查询
            // =====================================================

            // 1. 字段对字段关联
            $data['join']=$this->dump(
                $db->query(Query::select()->from('users','u')
                    ->join('left','orders o',array(
                        array('u.id','=','o.user_id'),
                    ))
                    ->field('u.id')
                    ->field('o.id','order_id'))
            );

            // 2. 关联条件带标量右值(绑定为参数)
            $data['join_scalar']=$this->dump(
                $db->query(Query::select()->from('users','u')
                    ->join('inner','orders o',array(
                        array('u.status','=',1),
                    )))
            );

            // =====================================================
            // 七、行锁(需在事务内生效)
            // =====================================================

            $data['lock']=$this->dump(
                $db->query(Query::select()->from('users')->where('id',1)->lock())
            );
            $data['lock_shared']=$this->dump(
                $db->query(Query::select()->from('users')->where('id',1)->lock('shared'))
            );

            // =====================================================
            // 八、事务
            // =====================================================

            // 1. 事务作用域: 回调内所有查询在同一个连接上执行, 抛异常自动回滚
            $data['transaction']=$this->transactionScope($db,function($tx) {
                $tx->query(Query::insert(array('name'=>'事务A','age'=>30,'status'=>1))->from('users'));
                $tx->query(Query::update(array('status'=>2))->from('users')->where('id',1));
            });

            // 2. 嵌套事务(通过保存点实现)
            $data['transaction_nested']=$this->transactionScope($db,function($tx) {
                $tx->query(Query::insert(array('name'=>'外层','age'=>31,'status'=>1))->from('users'));
                $tx->transaction(function($tx) {
                    $tx->query(Query::insert(array('name'=>'内层','age'=>32,'status'=>1))->from('users'));
                });
            });

            // 3. 手动事务
            $data['transaction_manual']=$this->manualTransaction($db);

            // =====================================================
            // 九、结果集遍历
            // =====================================================

            // Result 的结果集为可迭代集合, 支持 foreach / count / toArray
            $result=$db->query(Query::select()->from('users'));
            $data['result_count']=$result->getResults()->count();
            $iterated=array();
            foreach($result->getResults() as $row) {
                $iterated[]=$row['id']??null;
            }
            $data['result_iterate']=$iterated;

            // =====================================================
            // 十、错误处理
            // =====================================================

            // 执行失败时 Result 的 success 为 false, error 携带错误信息(不抛异常)
            $failed=$db->query(Query::select()->from('not_exist_table'));
            $data['error_handling']=array(
                'success'=>$failed->isSuccess(),
                'sql'=>$failed->getSql(),
                'error'=>$failed->getError(),
            );
        } catch(Throwable $e) {
            $data['fatal_error']=$e->getMessage();
        }
        return $data;
    }

    /**
     * 简洁示例(单条查询 + 结果检查)
     *
     * @access public
     * @return array
     */
    public function demo(): array {
        $db=$this->db;
        $result=$db->query(Query::select()->from('users')->where('id',1));
        $rows=$result->getResults()->toArray();
        return array(
            'sql'=>$result->getSql(),
            'row'=>$rows[0]??null,
            'is_empty'=>$result->getResults()->isEmpty(),
        );
    }

    /**
     * 在事务作用域内执行回调并返回结果
     *
     * @access private
     * @param Db $db 数据库入口
     * @param callable $callback 回调
     * @return string 结果描述
     */
    private function transactionScope(Db $db,callable $callback): string {
        try {
            $db->transaction($callback);
            return 'success';
        } catch(Throwable $e) {
            return 'rollback: '.$e->getMessage();
        }
    }

    /**
     * 手动事务示例
     *
     * @access private
     * @param Db $db 数据库入口
     * @return string 结果描述
     */
    private function manualTransaction(Db $db): string {
        try {
            $db->beginTransaction();
            $db->query(Query::insert(array('name'=>'手动','age'=>33,'status'=>1))->from('users'));
            $db->commit();
            return 'committed';
        } catch(Throwable $e) {
            try {
                $db->rollBack();
            } catch(Throwable $ignored) {
            }
            return 'rollback: '.$e->getMessage();
        }
    }

    /**
     * 汇总结果信息
     *
     * @access private
     * @param ResultInterface $result 查询结果
     * @return array
     */
    private function dump(ResultInterface $result): array {
        return array(
            'sql'=>$result->getSql(),
            'params'=>$result->getParams(),
            'success'=>$result->isSuccess(),
            'rows'=>$result->getResults()->toArray(),
            'affected'=>$result->getAffectedRows(),
            'error'=>$result->getError(),
        );
    }

}
