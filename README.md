# AdminService-Collaboration
## 该如何开始?
在开始之前,您应该需要注意\
本项目使用了 `PHP 8.1.0` 的语法结构, 所以您需要保证您的 PHP 版本不低于 `8.1.0`\
如果您在开发中有新增文件, 请确保您已经使用 [composer](https://www.phpcomposer.com/) 更新 `composer update`, 新增的文件如果没有及时更新, 新增的程序将无法通过 autoload 自动加载

您可以简单阅读下面的内容, 或者点击 [我该如何开始?](https://github.com/boringmj/adminservice-collaboration/wiki/准备) 阅读更详细的信息, 并帮助你编写第一个程序!

1. 您应该先下载或 `clone`(推荐) 本项目至本地
```
git clone https://github.com/boringmj/adminservice-collaboration.git
```
2. 通过 [composer](https://www.phpcomposer.com/) 安装依赖, 如果您没有下载 [composer](https://www.phpcomposer.com/), 您可以前往 [composer 官网](https://www.phpcomposer.com/) 获取帮助
```
// 前往项目路径
cd adminservice-collaboration
// 开发或测试环境直接使用 composer 安装依赖
composer install
// 生产环境下, 建议使用下面的命令
composer install --optimize-autoloader --no-dev
```
3. 启动 [php webserver](https://www.php.net/manual/zh/features.commandline.webserver.php), 我们提供了简单的快捷启动脚本
```
// 需要配置php环境变量且php>=5.4.0
php start
```
4. 访问 `localhost:8000`, 至此,您已经可以正常进行开发了\
如果您需要更多帮助,可以前往 [Wiki](https://github.com/boringmj/adminservice-collaboration/wiki/准备), 在那里有更加详细的教程和文档

## 路由
路由由`AdminService\Route`(继承`base\Route`)实现, 支持两种方式

**显式路由(默认)**: 在 [AdminService/routes/](AdminService/routes) 目录下按应用拆分注册, 目录内全部 `.php` 会被加载; 支持请求方法、路径参数、分组、资源路由与命名\
路由文件须返回接收路由表的闭包(显式传参, 便于静态分析)
```php
// AdminService/routes/demo.php
use AdminService\Router\Router;

return function(Router $router): void {

    $router->get('/user/{id:\d+}', array(\app\demo\controller\Index::class,'index'))->name('user.show');
    $router->post('/user', array(\app\demo\controller\Index::class,'request'));

    $router->group(array('prefix'=>'/api/v1'), function(Router $router): void {
        $router->get('/profile', array(\app\demo\controller\Index::class,'request'));
    });

};
```
路由文件列表由配置项 `route.files` 指定, 可写目录(加载其中全部 `.php`, 按文件名排序)或具体文件路径\
路径参数支持 `{name}` / `{name:约束}` / `{name?}`(可选, 须位于末尾); 路径参数写入请求的 `attributes` 区(不并入查询串), 合并取值 `$request->param('id')` 时优先于同名查询参数\
也可在控制器上就近声明路由(类级 `#[RouteGroup]` 定前缀与中间件, 方法级 `#[Route]` 定子路径/命名/中间件, 可重复声明), 框架自动扫描控制器目录注册(规则见 [Wiki](https://github.com/boringmj/adminservice-collaboration/wiki/开始#属性路由自动扫描))
```php
use base\Attribute\Middleware;
use base\Attribute\Route;
use base\Attribute\RouteGroup;

#[RouteGroup('/index')]                     // 类级: 前缀 + 中间件
#[Middleware(AuthMiddleware::class)]        // 类级: 控制器级中间件
class Index {

    #[Route('GET','/{name?}',name:'index.home')]
    public function index(string $name="World"): string { ... }
}
```
中间件四级, 由外向内: 请求(`middlewares.request`, 在路由匹配前执行, 未命中的请求同样经过)→ 分组 → 路由 → 控制器(`middlewares.controller` 与 `#[Middleware]`); 同层可用 `array('middleware'=>类名或实例,'priority'=>10)` 声明**层内优先级**(数值大者靠外, 同值保持声明顺序), 优先级不跨层\
未命中即返回 `404`; 路径存在但方法不符返回 `405` 并带 `Allow` 头; `OPTIONS` 以 `204` 应答; `HEAD` 由 `GET` 承接且不输出响应体\
您可以在`AdminService/Main.php`中查看路由的引用
```php
public function run(): void {
    $middlewares=(array)Config::get('middlewares.request',array());
    (new Pipeline($middlewares,App::get(Request::class)))->then(function(): void {
        App::fresh(Route::class)->run();
    });
}
```
如果路由并不适用于您的项目,你可以自由创建一个适用的路由类,并在`AdminService/Main.php`中引入您的类,并实例化
## 未来
我们将逐步构建出一个完善的轻量级快速响应框架,这可能需要非常久的时间\
我们由衷的希望大家提出意见,也由衷的欢迎大家加入到我们的开发之中\
如果您有疑问或其他事宜,请向`wuliaodemoji@wuliaomj.com`发送邮件,我们会在我们的能力范围内尽力为您解决问题\
\
Sorry, we are unable to provide additional language support due to limited language proficiency, but we welcome you to contact us and join us for additional support in your language\
Email: `wuliaodemoji@wuliaomj.com`
