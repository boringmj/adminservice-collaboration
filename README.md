# AdminService-Collaboration
## 该如何开始?
在开始之前,您应该需要注意\
本项目使用了 `PHP 8.0.0` 的语法结构, 所以您需要保证您的 PHP 版本不低于 `8.0.0`\
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
// composer 安装依赖
composer install
```
3. 启动 [php webserver](https://www.php.net/manual/zh/features.commandline.webserver.php), 我们提供了简单的快捷启动脚本
```
// 需要配置php环境变量且php>=5.4.0
php start
```
4. 访问 `localhost:8000`, 至此,您已经可以正常进行开发了\
如果您需要更多帮助,可以前往 [Wiki](https://github.com/boringmj/adminservice-collaboration/wiki/准备), 在那里有更加详细的教程和文档

## 路由
默认路由继承至`base\Route`基类,使用`AdminService\Route`实现, 请先配置您的 `webserver` 支持该路由形式
```
http[s]://domain/app/controller/method[/param1,/param2...]
或
// 使用下面的路由形式无须配置 webserver, 且可以支持多入口形式
http[s]://domain/?/app/controller/method[/param1,/param2...]
```
您可以在`AdminService/Main.php`中查看路由的引用
```
// 初始化请求
Request::init(new Cookie());
// 路由
try{
    // 路由
    $route=new Route(new Request());
    Request::requestExit($route->run());
} catch(Exception $e) {
    Request::requestExit($e->getMessage());
}
```
如果默认路由并不适用于您的项目,你可以自由创建一个适用的路由类,并在`AdminService/Main.php`中引入您的类,并实例化
## 未来
我们将逐步构建出一个完善的轻量级快速响应框架,这可能需要非常久的时间\
我们由衷的希望大家提出意见,也由衷的欢迎大家加入到我们的开发之中\
如果您有疑问或其他事宜,请向`wuliaodemoji@wuliaomj.com`发送邮件,我们会在我们的能力范围内尽力为您解决问题\
\
Sorry, we are unable to provide additional language support due to limited language proficiency, but we welcome you to contact us and join us for additional support in your language\
Email: `wuliaodemoji@wuliaomj.com`