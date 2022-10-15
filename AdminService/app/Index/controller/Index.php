<?php

namespace app\Index\controller;

use bash\Controller;

// count() method dependencies
use bash\Exception;
use bash\File;

class Index extends Controller {

    public function index() {
        return "Hello World!";
    }

    public function test() {
        return "Hi ".$this->param('name','AdminService')."!";
    }

    public function count() {
        try {
            $file=new File('count');
            $count=$file->get('count',0);
            $file->set('count',$count+1,true);
            return "Count: ".$file->get('count',0);
        }
        catch(Exception $e) {
            return $e->getMessage();
        }
    }

}

?>