<?php

namespace app\demo\controller;

use Throwable;
use AdminService\Db;
use app\demo\model\Order;
use app\demo\model\User;
use base\Controller;

/**
 * ORM 使用示例控制器
 *
 * - 基于 base/Model + ModelQueryBuilder 的现代风格 ORM 演示
 * - 访问: /demo/OrmDemo(schema 探查) 与 /demo/OrmDemo/index(完整示例)
 */
class OrmDemo extends Controller {

    public function test(): mixed {
        return $this->json(User::where('id',9999)->first());
    }

    /**
     * 探查 users 表结构
     *
     * @access public
     * @return mixed
     */
    public function schema(): mixed {
        try {
            $result=Db::connection()->raw('SHOW COLUMNS FROM admin_service_users');
            return $this->json(array(
                'success'=>$result->isSuccess(),
                'columns'=>$result->getResults()->toArray(),
                'error'=>$result->getError(),
            ));
        } catch(Throwable $e) {
            return $this->json(array('fatal_error'=>$e->getMessage()));
        }
    }

    /**
     * 关系示例(hasMany / belongsTo / 惰性加载 / 预加载)
     *
     * @access public
     * @return mixed
     */
    public function relation(): mixed {
        $data=array();
        $run=function($name,$callback) use (&$data) {
            try {
                $data[$name]=$callback();
            } catch(Throwable $e) {
                $data[$name]=array('error'=>$e->getMessage());
            }
        };
        // 惰性加载: $user->orders (hasMany)
        $run('lazy_orders',function() {
            $user=User::find(1);
            return array(
                'user_id'=>$user?->getKey(),
                'count'=>$user?->orders?->count()??0,
                'rows'=>$user?->orders?->toArray()??array(),
            );
        });
        // 预加载: with('orders') 批量取回, 避免 N+1
        $run('eager_orders',function() {
            $users=User::with('orders')->where('age',18,'>=')->limit(3)->get();
            return array_map(function($u) {
                return array('id'=>$u->getKey(),'orders'=>$u->orders->count());
            },$users->all());
        });
        // belongsTo: 订单属于用户
        $run('belongs_to',function() {
            $order=Order::with('user')->limit(3)->first();
            return array(
                'order_id'=>$order?->getKey(),
                'user_name'=>$order?->user?->name,
            );
        });
        // belongsToMany 惰性加载: $user->roles (两步查询: 中间表 → 相关表)
        $run('lazy_roles',function() {
            $user=User::find(1);
            return array(
                'user_id'=>$user?->getKey(),
                'roles'=>$user?->roles?->toArray()??array(),
            );
        });
        // belongsToMany 预加载: with('roles') 批量取回
        $run('eager_roles',function() {
            $users=User::with('roles')->limit(3)->get();
            return array_map(function($u) {
                return array('id'=>$u->getKey(),'roles'=>$u->roles->pluck('name'));
            },$users->all());
        });
        // 关系写入: hasMany()->create() 自动补外键(展示后清理)
        $run('relation_create',function() {
            $user=User::find(1);
            $order=$user->orders()->create(array(
                'order_no'=>'REL'.date('YmdHis'),
                'amount'=>mt_rand(1,1000).'.00',
                'status'=>1,
            ));
            $result=array(
                'order_id'=>$order->getKey(),
                'user_id'=>$order->user_id,
                'order_no'=>$order->order_no,
            );
            $order->delete(); // 清理演示数据
            return $result;
        });
        // 嵌套预加载: with('orders.user') 两层批量取回
        $run('nested_eager',function() {
            $users=User::with('orders.user')->limit(3)->get();
            return array_map(function($u) {
                return array(
                    'id'=>$u->getKey(),
                    'orders'=>array_map(function($o) {
                        return array('no'=>$o->order_no,'user'=>$o->user?->name);
                    },$u->orders->all()),
                );
            },$users->all());
        });
        // 中间表关联写入: attach 关联 → 验证 → detach 还原
        $run('m2m_attach',function() {
            $user=User::find(1);
            $before=$user->roles()->get()->pluck('id');
            $user->roles()->attach(array(3));       // 关联 viewer
            $after=$user->roles()->get()->pluck('id');
            $user->roles()->detach(array(3));        // 还原初始
            $restored=$user->roles()->get()->pluck('id');
            return array(
                'before'=>$before,
                'after'=>$after,
                'restored'=>$restored,
            );
        });
        return $this->json($data);
    }

    /**
     * 表前缀与限定字段编译验证
     *
     * - full_name: 直接 from 全表名(不再二次加前缀)
     * - qualified: 限定字段 users.id 映射到实际表名(前缀后)
     *
     * @access public
     * @return mixed
     */
    public function prefixCheck(): mixed {
        $data=array();
        $run=function($name,$callback) use (&$data) {
            try {
                $data[$name]=$callback();
            } catch(Throwable $e) {
                $data[$name]=array('error'=>$e->getMessage());
            }
        };
        // 全表名 from, 前缀不再重复(门面 Db::table)
        $run('full_name',function() {
            $b=Db::connection()->table('admin_service_users')->limit(1);
            $rows=$b->get();
            return array('rows'=>count($rows),'sql'=>$b->getSql());
        });
        // 限定字段 users.id, 编译期映射到 admin_service_users.id
        $run('qualified',function() {
            $b=Db::connection()->table('users')->field('users.id')->where('users.id',1)->limit(1);
            return array('rows'=>$b->get(),'sql'=>$b->getSql());
        });
        return $this->json($data);
    }

    /**
     * 跨模型事务示例(同一连接, 模型写入共享事务)
     *
     * - tx_success: 创建用户 + 关系创建订单, 同一事务提交
     * - tx_rollback: 创建用户后抛异常 → 用户一并回滚
     *
     * @access public
     * @return mixed
     */
    public function transaction(): mixed {
        $data=array();
        $run=function($name,$callback) use (&$data) {
            try {
                $data[$name]=$callback();
            } catch(Throwable $e) {
                $data[$name]=array('error'=>$e->getMessage());
            }
        };
        // 成功事务: 两个模型写在同一事务里提交
        $run('tx_success',function() {
            $result=Db::connection()->transaction(function() {
                $user=User::create(array('name'=>'TX成功'.mt_rand(1000,9999),'age'=>30,'status'=>1));
                $order=$user->orders()->create(array(
                    'order_no'=>'TX'.date('YmdHis'),
                    'amount'=>100,
                    'status'=>1,
                ));
                return array('user_id'=>$user->getKey(),'order_id'=>$order->getKey());
            });
            // 清理演示数据(不污染后续演示)
            $user=User::find($result['user_id']);
            $order=Order::find($result['order_id']);
            $order?->delete();
            $user?->delete();
            return $result;
        });
        // 回滚事务: 用户创建后抛异常 → 用户也应回滚(前后计数一致)
        $run('tx_rollback',function() {
            $before=User::query()->count();
            try {
                Db::connection()->transaction(function() {
                    User::create(array('name'=>'TX回滚'.mt_rand(1000,9999),'age'=>30,'status'=>1));
                    throw new \RuntimeException('trigger rollback');
                });
            } catch(Throwable $e) {
                // 期望回滚, 吞掉
            }
            $after=User::query()->count();
            return array('before'=>$before,'after'=>$after,'rolled_back'=>($before===$after));
        });
        return $this->json($data);
    }

    /**
     * 为 users 表补充 updated_at 列(时间戳支持)
     *
     * @access public
     * @return mixed
     */
    public function addUpdatedAt(): mixed {
        try {
            $result=Db::connection()->raw(
                'ALTER TABLE admin_service_users '
                .'ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP '
                .'ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
            );
            return $this->json(array('success'=>$result->isSuccess(),'error'=>$result->getError()));
        } catch(Throwable $e) {
            return $this->json(array('fatal_error'=>$e->getMessage()));
        }
    }

    /**
     * 完整 ORM 示例
     *
     * @access public
     * @return mixed
     */
    public function index(): mixed {
        $data=array();
        try {
            // ========== 查询 ==========

            // 按主键查找
            $data['find']=$this->dump(User::find(1));

            // refresh: 原地从库重载; fresh: 返回最新状态的新实例
            $user=User::find(1);
            $user->name='临时改名';
            $data['refresh_before']=$user->name;      // 本地改过的值
            $user->refresh();                          // 原地回库重载
            $data['refresh_after']=$user->name;        // 库中真实值
            $data['fresh_name']=$user->fresh()?->getAttribute('name'); // 新实例的库值

            // 条件 + 排序 + 限制数量(前 5 条)
            $data['query_get']=$this->dumpCollection(
                User::query()->where('age',18,'>=')->orderBy('id','DESC')->limit(5)->get()
            );

            // 分页: 每页 3 条, 第 2 页(自动统计总数 + 软删过滤)
            $page=User::query()->where('age',18,'>=')->paginate(3,2);
            $data['paginate']=array(
                'total'=>$page->total(),
                'per_page'=>$page->perPage(),
                'current_page'=>$page->currentPage(),
                'last_page'=>$page->lastPage(),
                'has_more'=>$page->hasMorePages(),
                'items'=>$page->items()->toArray(),
            );

            // 统计(软删除模型默认排除已删)
            $data['count']=User::query()->count();

            // 单字段/字段列表
            $data['value']=User::query()->where('id',1)->value('name');
            $data['pluck']=User::query()->limit(5)->pluck('name');

            // ========== 写入 ==========

            // 创建(自动时间戳 + 主键回填)
            $created=User::create(array(
                'name'=>'ORM创建'.mt_rand(1000,9999),
                'age'=>mt_rand(18,60),
                'status'=>1,
            ));
            $data['create']=array(
                'id'=>$created->getKey(),
                'exists'=>$created->exists(),
                'created_at'=>$created->created_at,
                'updated_at'=>$created->updated_at,
            );

            // 更新已存在记录(自动刷 updated_at)
            $created->name='ORM改名'.mt_rand(1000,9999);
            $data['save']=array('result'=>$created->save());

            // 软删除 / 恢复 / 物理删除
            $data['delete_soft']=array('result'=>$created->delete(),'trashed'=>$created->trashed());
            $data['restore']=array('result'=>$created->restore(),'trashed'=>$created->trashed());
            $data['delete_force']=array('result'=>$created->forceDelete(),'exists'=>$created->exists());

            // 批量操作
            $data['builder_delete']=User::query()->where('status',999)->delete();

            // 软删除过滤
            $data['with_trashed']=User::withTrashed()->where('id',1)->first()?->getAttribute('name');
            $data['only_trashed']=User::onlyTrashed()->count();
        } catch(Throwable $e) {
            $data['fatal_error']=$e->getMessage();
        }
        return $this->json($data);
    }

    /**
     * 汇总单个模型
     *
     * @access private
     * @param mixed $model 模型或 null
     * @return mixed
     */
    private function dump(mixed $model): mixed {
        return $model?->toArray()??null;
    }

    /**
     * 汇总模型集合
     *
     * @access private
     * @param mixed $collection 集合
     * @return array
     */
    private function dumpCollection(mixed $collection): array {
        if(!$collection)
            return array();
        return array(
            'count'=>$collection->count(),
            'rows'=>$collection->toArray(),
        );
    }

}
