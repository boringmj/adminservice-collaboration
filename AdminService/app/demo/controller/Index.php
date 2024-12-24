<?php

namespace app\demo\controller;

// 控制器基类
use base\Controller;
// 系统核心类
use AdminService\App;
use AdminService\Log;
use AdminService\Config;
use AdminService\Exception;
// 模型
use app\demo\model\Sql;
use app\demo\model\Count;
// 公共类
use AdminService\common\HttpHelper;

// 控制器助手函数
use function AdminService\common\view;
use function AdminService\common\json;

class Index extends Controller {

    public function index(): string {
        // 返回组字符串
        return "Hello World!";
    }

    public function request(): array {
        // 获取请求参数(更多用法请查看Request基类以及Request核心类)
        // $this->request的实际类型是AdminService\Request,你在调用方法时ide提示的则是base\Request
        // 所以如果你希望你的ide能够准确提示,建议引入AdminService\Request后直接使用AdminService\Request的静态方法
        return json(null,null,array(
            'name'=>$this->request->param('name','AdminService'), // 获取请求参数(CGP顺序)
            // 'name'=>$this->param('name','AdminService'), // 完全等价于上面的写法
            // 'name'=>$this->request->post('name','AdminService'), // 获取单个POST参数
            // 'name'=>$this->request->get('name','AdminService'), // 获取单个GET参数
            'post'=>$this->request->post(), // 获取所有POST参数
            'get'=>$this->request->get(), // 获取所有GET参数
            'cookie'=>$this->request->cookie(), // 获取所有COOKIE参数
            'input'=>$this->request->getInput(), // 获取输入流
            'files'=>$this->request->getUploadFile('files'), // 获取上传的文件
            'key'=>$this->request->keys('get') // 获取所有GET类型请求参数的键名(支持all|get|post|cookie且不区分大小写)
        ));
    }

    public function test(): string {
        // 返回视图,默认视图路径为 AdminService/app/demo/view/控制器名/方法名.html
        return $this->view(array(
            'name'=>$this->param('name','AdminService')
        ));
    }

    public function count(): string {
        // 这里展示通过App::get()来实例化类,支持自动依赖注入(因为传入了构造参数,所以不会放入容器中)
        // $count=App::get(Count::class,null); // 第二个参数实际传给Count构造方法的参数
        // 下面这种写法不会将没有构造参数的类放入容器中,且同样支持自动依赖注入
        $count=App::new(Count::class);
        return view('count',array(
            'count'=>$count->add()
        ));
    }

    public function sql(): array {
        // 这里展示动态代理类的使用(只有当你调用这个类时才会实例化,属于懒加载)
        // 调用被代理类的方法时,支持自动参数注入
        // 必须说明,因为动态代理的兼容性问题,所以不建议用在定义复杂的类上
        $test=App::proxy(Sql::class);
        // 返回json
        /** @var Sql $test 虽然实际上是代理类,但本质上还是Sql类 */
        return json(null,null,$test->test());
    }

    public function log(): string {
        // 通过 App::get() 传入自定义参数(如果不传入则会尝试自动注入,如果注入失败则会抛出异常)
        App::get("Log",'debug')->write("This is a debug message in {app}.",array(
            'app'=>App::getAppName()
        ));
        // 输出日志文件路径
        return "日志存放目录: ".realpath(Config::get('log.path',''));
    }

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
            // ============ 高危警告 ============
            // 安全警告: 如果使用用户提供的文件后缀名,请务必对文件进行检查,否则可能会导致文件上传漏洞
            // 您可以通过例如白名单的方式来限制文件后缀名,或者您将文件存放在非web目录下(不推荐的方式)
            // 更加安全的方式是将文件存放在数据库中,这样可以避免文件上传漏洞(缺点是数据库性能会受到影响)
            // 也可以通过完全随机命名,然后通过数据库来关联文件名和文件路径(推荐)
            // 总之,请不要轻易使用用户提供的文件名和后缀名,这对您的系统安全很重要
            // 为了实现最优的安全性，建议结合多种措施，例如：
            // 限制文件类型和大小：使用白名单限制文件类型，结合 MIME 类型检查。
            // 随机文件名：生成随机的文件名以避免文件名冲突。
            // 存储路径：将文件存储在非 web 目录下，并使用数据库记录文件的元数据。
            // 安全检查：在上传和处理文件时进行全面的安全检查，包括文件内容验证和权限控制。
            // 下面是一段简单的后缀名检查代码,这段代码被放在这里仅仅是为了演示(不保证安全)
            // ============ 高危警告 ============
            // 将白名单定义在循环外可以提高性能
            $file_ext_white_list=array('jpg','jpeg','png','gif','bmp','webp','txt');
            // 检查文件后缀名是否在白名单中
            if(!in_array($file['ext'],$file_ext_white_list)) {
                throw new Exception("文件后缀名不在白名单中",0,array(
                    'ext'=>$file['ext'],
                    'allow'=>$file_ext_white_list
                ));
            }
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

    public function curl(): string {
        // 这里展示curl请求,具体用法请查看HttpHelper类
        $url='https://www.baidu.com';
        // 快速发起GET请求
        return HttpHelper::get($url,array(),30,function($code,$body,$headers,$error) {
            throw new Exception("请求失败,详细请查看日志",500,array(
                'code'=>$code,
                'body'=>$body,
                'headers'=>$headers,
                'error'=>$error
            ),true); // 注意,disable_ssl_verify参数是true则会禁用ssl验证,这可能会导致安全问题
        });
    }

}