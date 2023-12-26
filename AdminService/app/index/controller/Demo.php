<?php

namespace app\index\controller;

// 控制器基类
use base\Controller;

// 系统核心类
use AdminService\App;

// 模型
use app\index\model\Count;
use app\index\model\Sql;

// 控制器助手函数
use function AdminService\common\view;
use function AdminService\common\json;

class Demo extends Controller {

    public function index() {
        // 返回字符串
        return "Hello World!";
    }

    public function test() {
        // 返回视图
        return $this->view(array(
            'name'=>$this->param('name','AdminService')
        ));
    }

    public function count() {
        // 这里展示通过App::get()来实例化(优点是支持自动依赖注入,缺点是兼容性不太好)
        $count=App::get(Count::class);
        return view('count', array(
            'count'=>$count->add()
        ));
    }

    public function sql() {
        // 这里展示动态代理类的使用(只有当你调用这个类时才会实例化,属于懒加载
        // 必须说明,因为动态代理的兼容性问题,所以不建议用在定义复杂的类上,比如上面的Count类就会出现问题
        $test=App::proxy(Sql::class);
        // 返回json
        return json(null,null,$test->test());
    }

    public function log() {
        // 通过 App::get() 传入自定义参数(如果不传入则会尝试自动注入,如果注入失败则会抛出异常)
        App::get("Log",'debug')->write("This is a debug message in {app}.",array(
            'app'=>App::getAppName()
        ));
        // 输出日志文件路径
        return "日志存放目录: ".realpath(\AdminService\Config::get('log.path'));
    }

}

?>