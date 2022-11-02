<?php

namespace app\index\model;

use base\Model;

class Sql extends Model {

    public function test(): string {
        return $this->table('a')->where('id',99)->where('name','user','!=')->select('id');
    }

}

?>