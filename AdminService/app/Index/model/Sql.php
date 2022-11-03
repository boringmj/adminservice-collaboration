<?php

namespace app\index\model;

use base\Model;

class Sql extends Model {

    public string $table_name='admin_service_system_info'; // 完整表名(含前缀)

    public function test() {
        return $this->where('app_id','thisdemo')->select(array('id','app_key'));
    }

    public function demo() {
        // 注意, 当这里传递入表名后, 后续请求的表名将会被覆盖
        return $this->table('system_info')->where('app_id','thisdemo')->select(array('id','app_key'));
    }
}

?>