<?php

namespace app\Index\controller;

use base\Controller;

// count() method dependencies
use AdminService\Exception;
use AdminService\File;
use AdminService\Request;
use AdminService\Cookie;

class Index extends Controller {

    public function index() {
        Request::setCookie('test','test1');
        Request::addCookie('test2','test2');
        Request::addCookie('test3','test3',60,'/admin','localhost');
        Cookie::set('test4','test4');
        Cookie::setByArray(array(
            'test5'=>'test5',
            'test6'=>array(
                'value'=>'test6',
                'expire'=>3600,
                'path'=>'/admin',
                'domain'=>'localhost',
                'secure'=>false,
                'httponly'=>false
            )
        ));
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