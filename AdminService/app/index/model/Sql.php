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
         * 4. 现在, 同一个字段已经支持多个where了, 他们是 AND 关系
         * 5. 目前还不知道怎么写 OR 关系, OR 和 AND 混用必然会出现一个控制优先级和结合性的问题, 目前还没有想到好的解决方案
         */

        

        # 查询一条数据(find 方法同样支持 select 方法的所有功能, 但是只会返回一条数据)
        return array(
            'data'=>$this->order('id DESC')->limit(1)->find(),
            'sql'=>$this->getLastSql()
        );

        # 查询全部
        // return $this->select();
        // return $this->select('*');
        # 反回迭代器(当数据量过大时,建议使用迭代器,否则可能会导致内存溢出)
        // return $this->iterator()->select();
        # 查询指定字段
        // return $this->select('id');
        // return $this->select(array('id','app_id'));
        // return $this->select(['id','app_id']);
        # 查询指定条件(链式)
        //return $this->where('id',1)->select();
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

        # 使用order排序(仅对 select 和 find 生效)
        // return $this->order('id')->select();
        # 目前是支持使用“`”符号的
        // return $this->order('`id`')->select();
        # 自定义排序方式 ASC DESC,请注意必须使用单个空格分隔
        // return $this->order('id DESC')->select();
        # 多个排序方式
        // return $this->order(array('id','DESC'),array('app_id','ASC'))->select();
        // return $this->order('id DESC','app_id ASC')->select();
        // return $this->order('`id` DESC',array('`app_id`','ASC'))->select();
        
        # 使用limit限制(仅对 select 生效)
        // return $this->limit(1)->select();
        // return $this->limit(1,2)->select();

        # 获取最后一次执行的SQL语句
        // return $this->getLastSql();

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
