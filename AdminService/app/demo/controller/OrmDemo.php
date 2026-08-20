<?php

namespace app\demo\controller;

use Throwable;
use app\demo\model\User;
use base\Controller;
use base\Database\Db;

/**
 * ORM 使用示例控制器
 *
 * - 基于 base/Model + ModelQueryBuilder 的现代风格 ORM 演示
 * - 访问: /demo/OrmDemo(schema 探查) 与 /demo/OrmDemo/index(完整示例)
 */
class OrmDemo extends Controller {

    /**
     * 探查 users 表结构
     *
     * @access public
     * @return mixed
     */
    public function schema(): mixed {
        try {
            $db=Db::fromConfig();
            $result=$db->raw('SHOW COLUMNS FROM admin_service_users');
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
     * 为 users 表补充 updated_at 列(时间戳支持)
     *
     * @access public
     * @return mixed
     */
    public function addUpdatedAt(): mixed {
        try {
            $db=Db::fromConfig();
            $result=$db->raw(
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

            // 条件 + 排序 + 分页
            $data['query_get']=$this->dumpCollection(
                User::query()->where('age',18,'>=')->orderBy('id','DESC')->limit(5)->get()
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
