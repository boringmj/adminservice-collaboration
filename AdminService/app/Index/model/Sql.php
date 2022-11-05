<?php

namespace app\index\model;

use base\Model;

class Sql extends Model {

    public string $table_name='admin_service_system_info'; // 数据表名(默认不启用自动添加数据表前缀)

    public function test() {
        // 使用 $table_name 作为表名

        /** 简单说明
         * 
         * 1. 数据库事务非强制开启, 如需使用请自行开启
         * 2. 所有方法均可能抛出 \PDOException 异常和 \AdminService\Exception 异常
         * 3. 更新 和 删除 均需提供 where 条件 或 包含主键的数据, 否则会抛出异常
         */

        # 开启事务
        $this->beginTransaction();
        try {
            # 插入数据
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
            # 通过主键更新数据(默认主键为id,暂不支持自定义主键)
            $this->update(
                array(
                    'id'=>1, // 主键
                    'app_id'=>time(),
                    'app_key'=>md5(time()),
                    'timestamp'=>time()
                )
            );
            # 通过where条件更新数据(值得说明,where会对下一个update的所有数据生效,但如果有数据包含主键,则会优先使用主键)
            $this->where('id',2)->update(
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
            # 通过主键删除数据(默认主键为id,暂不支持自定义主键)
            $this->delete(3);
            $this->delete(array(4,5));
            # 通过where条件删除数据
            $this->where('id',6,'>=')->delete();
        } catch(\AdminService\Exception $e) {
            # 回滚事务
            $this->rollBack();
            return $e->getMessage();
        }
        # 提交事务
        $this->commit();

        # 查询全部
        return $this->select();
        // return $this->select('*');
        # 查询指定字段
        // return $this->select('id');
        // return $this->select(array('id','app_id'));
        // return $this->select(['id','app_id']);
        # 查询指定条件(链式)
        //return $this->where('id',2,'<=')->select();
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
         # 查询指定条件(非链式)
        /* $this->where('id',1);
        return $this->select(); */

        /* // 这种写法我们不会支持, 因为这将与其他写法产生不必要的冲突
        return $this->where(
            array('id',1),
            array('app_id','oP%','LIKE')
        )->select(); */
    }

    public function demo() {
        // 传入表名,且自动添加前缀
        return $this->table('system_info')->where('id',1)->select(array('id','app_key'));
    }
}
