<?php

namespace app\index\model;

use base\Model;

class Test extends Model {

    public function a(): string {
        return $this->setTable('a')->where('id=1')->select('id');
    }

}

?>