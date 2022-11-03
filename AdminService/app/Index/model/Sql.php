<?php

namespace app\index\model;

use base\Model;

class Sql extends Model {

    public string $table_name='system_info'; // 数据表名(不包含前缀)

    public function test() {
        // 使用 $table_name 作为表名
        return $this->where('id',1)->select(array('id','app_key'));
    }

    public function demo() {
        // 传入表名,且自动添加前缀
        return $this->table('system_info')->where('id',1)->select(array('id','app_key'));
    }
}

?>