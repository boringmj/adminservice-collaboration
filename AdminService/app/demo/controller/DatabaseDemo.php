<?php

namespace app\demo\controller;

use Throwable;
use AdminService\Db;
use base\Controller;

/**
 * 数据库使用示例控制器(全门面 AdminService\Db)
 *
 * - 用户入口仅 AdminService\Db, 不接触底层 DBAL(base\Database)
 * - 访问: /demo/DatabaseDemo(完整示例) /demo/DatabaseDemo/demo(简洁) /demo/DatabaseDemo/facade(门面专讲)
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
     * 门面专讲(AdminService\Db::table 流式裸查询)
     *
     * @access public
     * @return mixed
     */
    public function facade(): mixed {
        $data=array();
        try {
            $data['get']=Db::table('users')->where('age',18,'>=')->orderBy('id')->limit(3)->get();
            $data['first']=Db::table('users')->where('id',1)->first();
            $data['count']=Db::table('users')->where('status',1)->count();
            $data['value']=Db::table('users')->where('id',1)->value('name');
            $data['pluck']=Db::table('users')->limit(5)->pluck('name');
        } catch(Throwable $e) {
            $data['fatal_error']=$e->getMessage();
        }
        return $this->json($data);
    }

    /**
     * 完整示例逻辑(全门面)
     *
     * @access public
     * @return array 带标签的结果集
     */
    public static function runDemo(): array {
        $data=array();
        try {
            // ============ 一、查询 ============
            $b=Db::table('users');
            $data['select_all']=$b->get();
            $data['select_all_sql']=$b->getSql();
            $data['select_fields']=Db::table('users')->field('id,name')->get();
            $b=Db::table('users');
            $data['order']=$b->order('id','DESC')->limit(5)->get();
            $data['order_sql']=$b->getSql();
            $data['where']=Db::table('users')->where('status',1)->where('age',18,'>=')->get();
            $data['where_like']=Db::table('users')->where('name','张%','LIKE')->get();
            $data['where_in']=Db::table('users')->whereIn('status',array(1,2))->get();
            $data['where_group']=Db::table('users')
                ->where('status',1)
                ->whereGroup('OR',function($q) {
                    $q->where('age',30,'<');
                    $q->where('name','张%','LIKE');
                })->get();
            $data['group']=Db::table('users')->field('status')->group('status')->get();
            $data['join']=Db::table('users','u')
                ->join('left','orders o',array(array('u.id','=','o.user_id')))
                ->field('u.id')
                ->field('o.id','order_id')
                ->get();
            $data['lock']=Db::table('users')->where('id',1)->lock()->get();

            // ============ 二、单条/聚合 ============
            $data['first']=Db::table('users')->where('id',1)->first();
            $data['count']=Db::table('users')->where('status',1)->count();
            $data['value']=Db::table('users')->where('id',1)->value('name');
            $data['pluck']=Db::table('users')->limit(5)->pluck('name');
            $page=Db::table('users')->paginate(3,2);
            $data['paginate']=array(
                'total'=>$page['total'],
                'per_page'=>$page['per_page'],
                'items'=>$page['items'],
            );

            // ============ 三、写入(展示后清理) ============
            $id=Db::table('users')->insert(array('name'=>'门面创建','age'=>20,'status'=>1));
            $affected=Db::table('users')->where('id',$id)->update(array('age'=>21));
            Db::table('users')->where('id',$id)->delete();
            $data['write']=array('insert_id'=>$id,'update_affected'=>$affected);

            // ============ 四、原生 SQL ============
            $data['raw']=Db::raw('SELECT COUNT(*) AS c FROM admin_service_users')->getResults()->toArray();

            // ============ 五、事务(成功更新 + 异常回滚) ============
            $data['transaction']=Db::transaction(function() {
                Db::table('users')->where('id',1)->update(array('status'=>2));
            });
            try {
                Db::transaction(function() {
                    Db::table('users')->insert(array('name'=>'回滚','age'=>40,'status'=>1));
                    throw new \RuntimeException('trigger rollback');
                });
                $data['transaction_rollback']='no rollback';
            } catch(\RuntimeException $e) {
                $data['transaction_rollback']='rolled back';
            }

            // ============ 六、错误处理(门面失败抛异常) ============
            try {
                Db::table('not_exist_table')->get();
                $data['error_handling']='no error';
            } catch(Throwable $e) {
                $data['error_handling']=$e->getMessage();
            }
        } catch(Throwable $e) {
            $data['fatal_error']=$e->getMessage();
        }
        return $data;
    }

    /**
     * 简洁示例逻辑(全门面)
     *
     * @access public
     * @return array
     */
    public static function runDemoSimple(): array {
        $b=Db::table('users')->where('id',1);
        return array(
            'sql'=>$b->getSql(),
            'row'=>$b->first(),
            'count'=>Db::table('users')->count(),
        );
    }

}
