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

**显式路由(默认)**: 在 [AdminService/routes/web.php](AdminService/routes/web.php) 中注册, 支持请求方法、路径参数、分组、资源路由与命名
```php
// AdminService/routes/web.php

$router->get('/user/{id:\d+}', array(\app\demo\controller\Index::class,'index'))->name('user.show');
$router->post('/user', array(\app\demo\controller\Index::class,'request'));

$router->group(array('prefix'=>'/api/v1'), function(\AdminService\Router\Router $router): void {
    $router->get('/profile', array(\app\demo\controller\Index::class,'request'));
});
```
**约定式路由(可选)**: 按 `/app/controller/method` 定位控制器, 由配置项 `route.convention_fallback` 开启\
默认关闭: 未命中显式路由时返回 `404`, 不泄漏目录结构; 开启后请先[配置](https://github.com/boringmj/adminservice-collaboration/wiki/开始#默认路由)您的 `webserver` 支持该路由形式
```
http[s]://domain/app/controller/method[/param1,/param2...][?get=value&...]
或
// 使用下面的路由形式无须配置 webserver, 且可以支持多入口形式
http[s]://domain[/web_path]/?/app/controller/method[/param1,/param2...][/&get=value&...]

// 例如
http://localhost:8000/index/index/index?get=value
http://localhost:8000/?/index/index/index&get=value
http://localhost:8000/index/index/index/get/value
```
您可以在`AdminService/Main.php`中查看路由的引用
```php
public function run(): void {
    $route=App::get(Route::class);
    $route->run();
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
