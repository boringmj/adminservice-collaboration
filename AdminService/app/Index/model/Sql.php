<?php

namespace app\index\model;

use base\Model;

class Sql extends Model {

    public function test() {
        return $this->table('system_info')->where('app_id','thisisdemo')->select();
    }

}

?>