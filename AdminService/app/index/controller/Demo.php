<?php

namespace app\index\controller;

// 控制器基类
use base\Controller;

// 系统核心类
use AdminService\App;
use AdminService\Log;

// 模型
use app\index\model\Count;
use app\index\model\Sql;

// 控制器助手函数
use function AdminService\common\view;
use function AdminService\common\json;

class Demo extends Controller {

    public function index() {
        return "Hello World!";
    }

    public function test() {
        return $this->view(array(
            'name'=>$this->param('name','AdminService')
        ));
    }

    public function count() {
        $count=new Count();
        return view('count', array(
            'count'=>$count->add()
        ));
    }

    public function sql() {
        // 预览 Demo, 该方法随时可能被删除
        $test=new Sql();
        return json(null,null,$test->test());
    }

    public function log() {
        // 通过手动实例化日志类记录日志(优点是可以自定义日志文件名,缺点是需要手动实例化)
        $log=new Log("debug");
        $log->write("This is a debug message.");
        // 通过App类记录日志(优点是不需要手动实例化,缺点是日志文件名依赖于配置 “log.default_file” )
        App::get("Log")->write("This is a debug message in {name}.",array(
            'name'=>'App class'
        ));
        // 输出日志文件路径
        return "日志存放目录: ".\AdminService\Config::get('log.path');
    }

}

?>