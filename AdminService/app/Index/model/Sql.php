<?php

namespace app\index\model;

use base\Model;

class Sql extends Model {

    public string $table_name='admin_service_system_info'; // 数据表名(默认不启用自动添加数据表前缀)

    public function test() {
        // 使用 $table_name 作为表名

        # 插入数据
        $this->beginTransaction();
        try {
            $this->insert(
                array(
                    'app_id'=>time(),
                    'app_key'=>md5(time()),
                    'timestamp'=>time()
                ),
                array(
                    'app_id'=>'a'.time(),
                    'app_key'=>md5(time()),
                    'timestamp'=>time()
                )
            );
        } catch(\AdminService\Exception $e) {
            $this->rollBack();
            return $e->getMessage();
        }
        $this->commit();

        # 查询全部
        return $this->select();
        // return $this->select('*');
        # 查询指定字段
        // return $this->select('id');
        // return $this->select(array('id','app_id'));
        // return $this->select(['id','app_id']);
        # 查询指定条件(链式)
        // return $this->where('id',1)->select();
        // return $this->where('id',1,'>=')->select();
        // return $this->where('app_id','oP%','LIKE')->select();
        // return $this->where('id',1)->where('app_id','oP%','LIKE')->select();
        /* return $this->where(array(
            'id'=>1,
            'app_id'=>'oPxovtNTFoK3Tazcs1JWYY1662356577'
        ))->select(); */
        /* return $this->where(array(
            'id'=>array(1,'='),
            'app_id'=>array('oP%','LIKE')
        ))->select(); */
        /* return $this->where(array(
            'id'=>1,
            'app_id'=>array('oP%','LIKE')
        ),null,'=')->select(); */
        /* return $this->where(
            array(
                array('id',1),
                array('app_id','oP%','LIKE')
            )
        )->select(); */
        /* // 这种写法还在考虑是否需要支持,我们不推荐使用这种写法,您可以使用上面的写法完成同样的功能
        return $this->where(
            array('id',1),
            array('app_id','oP%','LIKE')
        )->select(); */
        # 查询指定条件(非链式)
        /* $this->where('id',1);
        return $this->select(); */

    }

    public function demo() {
        // 传入表名,且自动添加前缀
        return $this->table('system_info')->where('id',1)->select(array('id','app_key'));
    }
}
