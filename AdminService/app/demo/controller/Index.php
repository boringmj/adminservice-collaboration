<?php

namespace app\demo\controller;

// 控制器基类
use base\Controller;

// 系统核心类
use AdminService\App;
use AdminService\Log;
// 模型
use app\demo\model\Sql;
use app\demo\model\Count;
// 异常类
use \ReflectionException;
use AdminService\Exception;

// 控制器助手函数
use function AdminService\common\view;
use function AdminService\common\json;

class Index extends Controller {

    public function index(): string {
        // 返回组字符串
        return "Hello World!";
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function request(): array {
        // 获取请求参数(更多用法请查看Request基类以及Request核心类)
        // $this->request的实际类型是AdminService\Request,你在调用方法时ide提示的则是base\Request
        // 所以如果你希望你的ide能够准确提示,建议引入AdminService\Request后直接使用AdminService\Request的静态方法
        return json(null,null,array(
            'name'=>$this->request->param('name','AdminService'), // 获取请求参数(CGP顺序)
            // 'name'=>$this->param('name','AdminService'), // 完全等价于上面的写法
            // 'post'=>$this->request->post('name','AdminService'), // 获取单个POST参数
            // 'name'=>$this->request->get('name','AdminService'), // 获取单个GET参数
            'post'=>$this->request->post(), // 获取所有POST参数
            'get'=>$this->request->get(), // 获取所有GET参数
            'cookie'=>$this->request->cookie(), // 获取所有COOKIE参数
            'input'=>$this->request->getInput(), // 获取输入流
            'files'=>$this->request->getUploadFile('files'), // 获取上传的文件
            'key'=>$this->request->keys('get') // 获取所有GTE类型请求参数的键名(支持all|get|post|cookie且不区分大小写)
        ));
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function test(): string {
        // 返回视图,默认视图路径为 AdminService/app/demo/view/控制器名/方法名.html
        return $this->view(array(
            'name'=>$this->param('name','AdminService')
        ));
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function count(): string {
        // 这里展示通过App::get()来实例化(优点是支持自动依赖注入,缺点是兼容性不太好)
        $count=App::get(Count::class,null); // 第二个参数实际传给Count构造方法的参数
        return view('count',array(
            'count'=>$count->add()
        ));
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function sql(): array {
        // 这里展示动态代理类的使用(只有当你调用这个类时才会实例化,属于懒加载
        // 必须说明,因为动态代理的兼容性问题,所以不建议用在定义复杂的类上
        $test=App::proxy(Sql::class);
        // 返回json
        /** @var Sql $test 虽然实际上是代理类,但本质上还是Sql类 */
        return json(null,null,$test->test());
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function log(): string {
        // 通过 App::get() 传入自定义参数(如果不传入则会尝试自动注入,如果注入失败则会抛出异常)
        App::get("Log",'debug')->write("This is a debug message in {app}.",array(
            'app'=>App::getAppName()
        ));
        // 输出日志文件路径
        return "日志存放目录: ".realpath(\AdminService\Config::get('log.path'));
    }

    /**
     * @throws Exception
     * @throws ReflectionException
     */
    public function exec(): array {
        // 补充说明: 如果形参要求了类型,但传入参数不符合该类型,则会跳过该参数并采用默认值,如果没有默认值则会抛出异常
        // 如果传入的是顺位参数且该参数同样不符合形参类型,则该参数不计入顺位参数的位置且同样使用默认值或抛出异常

        // 调用类方法(如果第一个参数是类名则会自动实例化,如果是对象会直接调用)
        App::exec_class_function(Log::class,'write',array(
            'This is a debug message in {app} demo1.',
            array(
                'app'=>App::getAppName()
            )
        ));
        // 调用函数
        return App::exec_function('AdminService\common\json',array(
            "msg"=>"Hello World!", // 指定参数名
            200, // 顺位参数(指定参数不占用顺位参数位置)
        ));
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function foreach_view(): string {
        // 这里展示视图中的foreach语法,目前只支持两种遍历形式:一维索引数组和二维关联数组
        return $this->view('foreach',array(
            'name'=>'AdminService',
            'list1'=>array(
                'list1.demo1','list1.demo2'
            ),
            'list2'=>array(
                array(
                    'value'=>'list2.demo1'
                ),
                array(
                    'value'=>'list2.demo2'
                )
            )
        ));
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function upload(): string|array {
        // 这里展示文件上传,支持多文件上传和单文件上传
        $files=$this->request->getUploadFile('files');
        // 判断是否有文件被上传,如果没有则返回上传页面
        if(empty($files)) {
            return $this->view('upload');
        }
        $list=array();
        // 处理上传的文件
        foreach($files as $file) {
            // 保存文件(文件名为sha1值,后缀为文件后缀)
            $path=$file['sha1'].'.'.$file['ext'];
            // 请注意,如果不指定具体路径则会保存到运行目录下(默认运行目录是public目录)
            move_uploaded_file($file['tmp_name'],$path);
            $list[]=array(
                'name'=>$file['name'],
                'path'=>realpath($path)
            );
        }
        return json(null,null,array(
            'list'=>$list
        ));
    }

}