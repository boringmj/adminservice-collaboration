# AdminService-Collaboration
## 如何开始?
1. 您应该先下载或 `clone`(推荐) 本项目至本地
```
git clone https://github.com/boringmj/adminservice-collaboration
```
2. 通过 [composer](https://www.phpcomposer.com/) 安装依赖,如果您没有下载 [composer](https://www.phpcomposer.com/), 您可以前往 [composer 官网](https://www.phpcomposer.com/) 获取帮助
```
composer install
```
3. 启动 [php webserver](https://www.php.net/manual/zh/features.commandline.webserver.php), 我们提供了简单的快捷启动脚本
```
// 需要配置php环境变量且php>=5.4.0
php start
```
4. 访问 `localhost:8000`, 至此,您已经可以正常开发开发本框架了

## 路由
默认路由继承至`bash\Route`基类,使用`AdminService/Route`实现, 请先配置您的 `webserver` 支持该路由形式
```
http[s]?://domain/app/controller/method[/params1,/params2...]?
```
您可以在`AdminService/Main`中查看路由的引用
```
// 路由
$route=new Route();
try{
    $route_load=$route->load();
    Request::params($route_load['params']);
    Request::requestExit($route->run());
} catch(Exception $e) {
    Request::requestExit($e->getMessage());
}
```
## 未来
我们将逐步构建出一个完善的轻量级快速响应框架,这可能需要非常久的时间\
我们由衷的希望大家提出意见,也由衷的欢迎大家加入到我们的开发之中\
如果您有疑问或其他事宜,请向`wuliaodemoji@wuliaomj.com`发送邮件,我们会在我们的能力范围内尽力为您解决问题
