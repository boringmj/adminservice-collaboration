<?php

namespace app\demo\controller;

use Throwable;
use base\Controller;
use base\Database\Db;
use base\Database\Query\Query;
use base\Database\Result\ResultInterface;

/**
 * 数据库使用示例控制器
 *
 * - 新 DBAL(Query + Db)完整用法示例, 真实执行并返回 SQL 与结果
 * - 访问: /demo/DatabaseDemo 或 /demo/DatabaseDemo/index(完整示例); /demo/DatabaseDemo/demo(简洁示例)
 * - 需先配置数据库连接并创建示例数据表(见 AdminService/data/database.sql)
 */
class DatabaseDemo extends Controller {

    /**
     * 完整示例
     *
     * @access public
     * @return mixed
     */
    public function index(): mixed {
        try {
            return $this->json(self::runDemo());
        } catch(Throwable $e) {
            return $this->json(array('fatal_error'=>$e->getMessage()));
        }
    }

    /**
     * 简洁示例
     *
     * @access public
     * @return mixed
     */
    public function demo(): mixed {
        try {
            return $this->json(self::runDemoSimple());
        } catch(Throwable $e) {
            return $this->json(array('fatal_error'=>$e->getMessage()));
        }
    }

    /**
     * 完整示例逻辑(可注入 Db 便于测试)
     *
     * @access public
     * @param Db|null $db 数据库入口
     * @return array 带标签的结果集
     */
    public static function runDemo(?Db $db=null): array {
        $db=$db??Db::fromConfig();
        $data=array();
        try {
            // =====================================================
            // 一、查询
            // =====================================================

            $data['select_all']=self::dump(
                $db->query(Query::select()->from('users'))
            );
            $data['select_fields']=self::dump(
                $db->query(Query::select()->from('users')->field('id,name'))
            );
            $data['select_fields_array']=self::dump(
                $db->query(Query::select()->from('users')->field(array('id','name')))
            );
            $data['select_alias']=self::dump(
                $db->query(Query::select()->from('users','u')
                    ->field('u.id')
                    ->field(array('u.name'=>'nickname')))
            );
            $data['distinct']=self::dump(
                $db->query(Query::select()->from('users')->distinct()->field('status'))
            );
            $data['order']=self::dump(
                $db->query(Query::select()->from('users')->order('id','DESC'))
            );
            $data['order_multi']=self::dump(
                $db->query(Query::select()->from('users')
                    ->order('status','ASC')
                    ->order('id','DESC'))
            );
            $data['limit']=self::dump(
                $db->query(Query::select()->from('users')->limit(10))
            );
            $data['limit_offset']=self::dump(
                $db->query(Query::select()->from('users')->limit(10,20))
            );
            $data['offset']=self::dump(
                $db->query(Query::select()->from('users')->offset(20))
            );
            $data['group']=self::dump(
                $db->query(Query::select()->from('users')->group('status'))
            );

            // =====================================================
            // 二、条件
            // =====================================================

            $data['where_eq']=self::dump(
                $db->query(Query::select()->from('users')->where('id',1))
            );
            $data['where_gt']=self::dump(
                $db->query(Query::select()->from('users')->where('age',18,'>='))
            );
            $data['where_like']=self::dump(
                $db->query(Query::select()->from('users')->where('name','张%','LIKE'))
            );
            $data['where_in']=self::dump(
                $db->query(Query::select()->from('users')->whereIn('status',array(1,2,3)))
            );
            $data['where_not_in']=self::dump(
                $db->query(Query::select()->from('users')->whereNotIn('status',array(4,5)))
            );
            $data['where_between']=self::dump(
                $db->query(Query::select()->from('users')->whereBetween('age',18,60))
            );
            $data['where_not_between']=self::dump(
                $db->query(Query::select()->from('users')->whereBetween('age',18,60,true))
            );
            $data['where_null']=self::dump(
                $db->query(Query::select()->from('users')->whereNull('deleted_at'))
            );
            $data['where_not_null']=self::dump(
                $db->query(Query::select()->from('users')->whereNull('deleted_at',true))
            );
            $data['where_and']=self::dump(
                $db->query(Query::select()->from('users')
                    ->where('status',1)
                    ->where('age',18,'>='))
            );
            $data['where_group']=self::dump(
                $db->query(Query::select()->from('users')
                    ->where('status',1)
                    ->whereGroup('OR',function($q) {
                        $q->where('age',30,'<')
                          ->where('name','张%','LIKE');
                    }))
            );
            $data['where_qualified']=self::dump(
                $db->query(Query::select()->from('users','u')
                    ->where('u.id',1))
            );

            // =====================================================
            // 三、单条查询
            // =====================================================

            $data['find']=self::dump(
                $db->query(Query::find()->from('users')->where('id',1))
            );

            // =====================================================
            // 四、写入
            // =====================================================

            $data['insert']=self::dump(
                $db->query(Query::insert(array(
                    'name'=>'张三',
                    'age'=>20,
                    'status'=>1,
                ))->from('users'))
            );
            $data['insert_multi']=self::dump(
                $db->query(Query::insert(array(
                    array('name'=>'李四','age'=>21,'status'=>1),
                    array('name'=>'王五','age'=>22,'status'=>1),
                ))->from('users'))
            );
            $data['update']=self::dump(
                $db->query(Query::update(array('name'=>'张三三','age'=>21))
                    ->from('users')
                    ->where('id',1))
            );
            $data['delete_where']=self::dump(
                $db->query(Query::delete()->from('users')->where('status',0))
            );
            $data['delete_by_id']=self::dump(
                $db->query(Query::delete()->from('users')->whereIn('id',array(10,11)))
            );

            // =====================================================
            // 五、聚合统计
            // =====================================================

            $data['count']=self::dump(
                $db->query(Query::count()->from('users'))
            );
            $data['count_distinct']=self::dump(
                $db->query(Query::count()->from('users')->distinct()->field('status'))
            );
            $data['count_group']=self::dump(
                $db->query(Query::count()->from('users')->group('status'))
            );

            // =====================================================
            // 六、关联查询
            // =====================================================

            $data['join']=self::dump(
                $db->query(Query::select()->from('users','u')
                    ->join('left','orders o',array(
                        array('u.id','=','o.user_id'),
                    ))
                    ->field('u.id')
                    ->field('o.id','order_id'))
            );
            $data['join_scalar']=self::dump(
                $db->query(Query::select()->from('users','u')
                    ->join('inner','orders o',array(
                        array('u.status','=',1),
                    )))
            );

            // =====================================================
            // 七、行锁(需在事务内生效)
            // =====================================================

            $data['lock']=self::dump(
                $db->query(Query::select()->from('users')->where('id',1)->lock())
            );
            $data['lock_shared']=self::dump(
                $db->query(Query::select()->from('users')->where('id',1)->lock('shared'))
            );

            // =====================================================
            // 八、事务
            // =====================================================

            $data['transaction']=self::transactionScope($db,function($tx) {
                $tx->query(Query::insert(array('name'=>'事务A','age'=>30,'status'=>1))->from('users'));
                $tx->query(Query::update(array('status'=>2))->from('users')->where('id',1));
            });
            $data['transaction_nested']=self::transactionScope($db,function($tx) {
                $tx->query(Query::insert(array('name'=>'外层','age'=>31,'status'=>1))->from('users'));
                $tx->transaction(function($tx) {
                    $tx->query(Query::insert(array('name'=>'内层','age'=>32,'status'=>1))->from('users'));
                });
            });
            $data['transaction_manual']=self::manualTransaction($db);

            // =====================================================
            // 九、结果集遍历
            // =====================================================

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
     * 简洁示例逻辑
     *
     * @access public
     * @param Db|null $db 数据库入口
     * @return array
     */
    public static function runDemoSimple(?Db $db=null): array {
        $db=$db??Db::fromConfig();
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
    private static function transactionScope(Db $db,callable $callback): string {
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
    private static function manualTransaction(Db $db): string {
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
    private static function dump(ResultInterface $result): array {
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
