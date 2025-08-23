<?php

namespace app\demo\controller;

// 基类
use base\Controller;
// 系统核心类
use AdminService\App;
use AdminService\Log;
use AdminService\Config;
use AdminService\Exception;
use AdminService\UploadStorage;
// 模型
use app\demo\model\Sql;
use app\demo\model\Count;
use app\demo\model\SystemInfo;
// 过滤器
use app\demo\validator\Test as TestValidator;
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
        // 从get参数中获取指定参数
        $name=$this->request->getGet('name');
        // 从post参数中获取指定参数
        $name=$this->request->getPost('name');
        // 从cookie中获取指定参数
        $name=$this->request->getCookie('name');
        return json([
            'name'=>$name,
            'raw_input'=>$this->request->getRawInput(),
            'get'=>$this->request->getGets(),
            'post'=>$this->request->getPosts(),
            // 因为request_cookie是实现类成员,不属于基类成员,所以一般编辑器都会提示错误
            // 'cookie'=>$this->request::$request_cookie->all() // 看着难受等后续更新吧
        ],200);
    }

    public function view_demo(): string {
        // 返回视图,默认视图路径为 AdminService/app/demo/view/控制器名/方法名.html
        // 也可以直接传入视图名称 (不带后缀名),此时会在默认视图路径下查找
        return view(array(
            'name'=>'AdminService',
            'list1'=>array(
                'list1.demo1','list1.demo2'
            ),
            'list2'=>array(
                array(
                    'data'=>array(
                        'value'=>'list2.demo1',
                        'name'=>'name1'
                    )
                ),
                array(
                    'data'=>array(
                        'value'=>'list2.demo2',
                        'name'=>'name2'
                    )
                )
            ),
            'condition1'=>true,
            'condition2'=>3,
            'count'=>array(
                'value'=>App::get(Count::class)->add(),
                'condition'=>5
            ),
            'demo'=>array(
                'list'=>array(1,2,3,4,5,6,7,8,9,10)
            )
        ));
    }

    public function validator(TestValidator $validator,$name='AdminService'): array {
        // 可以通过在路由参数中传入不同的name参数来触发验证
        $scene='all'; // 场景,具体查看`TestValidator`中定义的场景
        // 这里演示使用`scene()`方法启用场景支持,如果不使用则不自动使用全部规则验证
        // 场景需要自己定义,具体请查看`TestValidator`类的示例
        // 如果指定了一个不存在的场景视为不使用规则集验证(默认通过验证)
        if($validator->scene($scene)->validate([
            'name'=>$name,
            'pass'=>'Pass',
            'int'=>6,
        ])) {
            return json($name);
        }
        return json($validator->getErrors(false,true));
    }

    public function sql(SystemInfo $systemInfo): array {
        // 这里展示动态代理类的使用(只有当你调用这个类时才会实例化,属于懒加载)
        // 调用被代理类的方法时,支持自动参数注入
        // 必须说明,因为动态代理的兼容性问题,所以不建议用在定义复杂的类上
        // `instance()` 方法属于助手类方法,传入true则返回代理类
        // 但编辑器会把他当做被代理的类,所以使用这种方法让编辑器支持代理类解析是有风险的
        // 默认为false,即返回真实对象,后续调用将失去代理类的支持,但无类型安全无风险
        $test=App::proxy(Sql::class)->instance(true);
        // 返回json
        return json($test->test());
        // 这里还展示了ORM的用法(目前支持有限,将来会支持更多)
        // return json($systemInfo->select()->toArray());
        // 更多用法(注意可能会抛出异常,特别是查找不存在的字段时,还需要注意结果是否为空)
        // $data=$systemInfo->test();
        // $id=$data->id;
    }

    public function log(): string {
        // 通过 App::get() 传入自定义参数(如果不传入则会尝试自动注入,如果注入失败则会抛出异常)
        App::get(Log::class,'debug')->write("This is a debug message in {app}.",array(
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

    public function upload(): string|array {
        // ========================================
        // 警告: 上传文件存在一定安全风险,请谨慎使用
        // ========================================
        // 这里展示文件上传,支持多文件上传和单文件上传
        $files=$this->request->getUploadFiles('files');
        // 判断是否有文件被上传,如果没有则返回上传页面
        if(count($files)==0) {
            return $this->view('upload');
        }
        // 返回所有上传文件信息
        // return $this->json($files->toArray());
        // 保存所有上传文件(风险警告:使用原后缀名存储,存在一定安全风险)
        $upload_storage=App::get(UploadStorage::class);
        foreach($files as $file) {
            $file->save($upload_storage);
        }
        return $this->json($files->toArray());
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