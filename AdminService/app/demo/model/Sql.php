<?php

namespace app\demo\model;

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
         * 4. 现在已经支持 order 和 limit 了
         * 5. 现在已经可以通过更加复杂的 whereEx 构造复合型 where 了,同时也支持构造 OR 语句
         * 6. 需要注意的是, whereEx 为了更方便以及更合理, 参数由 (<字段>,<值>,[操作符]) 改为 (<字段>,<值/操作符>,[操作符:<值>])
         *      例如 where 的 ("id",$id,"=") 在 whereEx 中你可以这样写: ("id",$id) 或者 ("id","=",$id)
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
            # 通过where条件更新数据(值得说明,where会对下一个update的所有数据生效,但如果有数据包含主键,则同样会使用主键作为条件)
            $this->where('id',2,'>=')->where('id',3,'<')->update(
                array(
                    'id'=>2, // 主键,与where条件是 AND 关系
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

        # 查询一条数据(find 方法同样支持 select 方法的所有功能, 但是只会返回一条数据)
        // return $this->find(); 

        // 补充说明, 下面是一条极其复杂的SQL语句, 解释了诸多新特性, 可以尝试修改并查看SQL语句的变化(错误条件并不一定会有实际数据返回)
        # 开启事务
        $this->beginTransaction();
        try {
            $data=array(
                'data'=>$this->where('id',1)->whereEx(array('app_id','LIKE','op%'))->whereEx(array(
                    array('id',2),
                    array('app_key','IN',array(1,2)),
                    array(
                        array('id',3),
                        array('app_key','LIKE','op%')
                    ),
                    'OR'
                ))->order('id DESC')->limit(1,1)->lock()->group("app_id")->select(),
                'sql'=>$this->getLastSql()
            );
            # 提交事务
            $this->commit();
            return $data;
        } catch(\AdminService\Exception $e) {
            # 回滚事务
            $this->rollBack();
            return $e->getMessage();
        }

        # 查询全部
        // return $this->select();
        // return $this->select('*');
        # 反回迭代器(当数据量过大时,建议使用迭代器,否则可能会导致内存溢出)
        // return $this->iterator()->select();
        # 查询指定字段
        // return $this->select('id');
        // return $this->select(array('id','app_id'));
        // return $this->select(['id','app_id']);
        // 使用IN操作符(目前支持的where操作符仅有: =,>,<,>=,<=,!=,<>,LIKE,NOT LIKE,IN')
        // return $this->select('id',array(1,2,3),'IN');
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

        # 使用order排序(仅对 select 和 find 生效),可以多次调用
        // return $this->order('id')->select();
        # 目前是支持使用“`”符号的
        // return $this->order('`id`')->select();
        # 自定义排序方式 ASC DESC,请注意必须使用单个空格分隔
        // return $this->order('id DESC')->select();
        # 多个排序方式
        // return $this->order(array('id','DESC'),array('app_id','ASC'))->select();
        // return $this->order('id DESC','app_id ASC')->select();
        // return $this->order('`id` DESC',array('`app_id`','ASC'))->select();
        
        # 使用limit限制(仅对 select 和 count 生效)且仅生效最后一个limit
        // return $this->limit(1)->select();
        // return $this->limit(1,2)->select();

        # 使用group分组(仅对 select 和 count 生效)
        // return $this->group('id')->select();
        // return $this->group(array('id','app_id'))->select();
        // return $this->group('id','app_id')->select();

        # 使用count统计
        // return $this->count();
        // return $this->group('id')->count();
        
        # 行锁(仅在事务中生效)
        // 共享锁
        // $this->lock('shared');
        // 排他锁
        // $this->lock('update');

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

?>