<?php

namespace app\index\model;

use base\Model;

class Sql extends Model {

    public string $table_name='system_info'; // 数据表名(不包含前缀)

    public function test() {
        // 使用 $table_name 作为表名

        # 查询全部
        $this->select();
        $this->select('*');
        # 查询指定字段
        $this->select('id');
        $this->select(array('id','name'));
        $this->select(['id','name']);
        # 查询指定条件(链式)
        $this->where('id',1)->select();
        $this->where('id',1,'=')->select();
        $this->where('name','admin','LIKE')->select();
        $this->where('id',1)->where('name','admin','LIKE')->select();
        $this->where(array(
            'id'=>1,
            'name'=>'admin'
        ))->select();
        $this->where(array(
            'id'=>array(1,'='),
            'name'=>array('admin','LIKE')
        ))->select();
        $this->where(array(
            'id'=>1,
            'name'=>array('admin','LIKE')
        ),null,'=')->select();
        $this->where(
            array('id',1),
            array('name','admin','LIKE')
        )->select();
        # 查询指定条件(非链式)
        $this->where('id',1);
        $this->select();

    }

    public function demo() {
        // 传入表名,且自动添加前缀
        return $this->table('system_info')->where('id',1)->select(array('id','app_key'));
    }
}

?>