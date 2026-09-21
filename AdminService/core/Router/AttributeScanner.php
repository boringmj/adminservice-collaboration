<?php

namespace AdminService\Router;

use function basename;
use function class_exists;
use function dirname;
use function glob;
use function rtrim;

/**
 * 属性路由扫描器
 *
 * - 按约定在应用目录下发现控制器类, 交给 {@see Router::registerAttributes()} 注册 `#[Route]`
 * - PHP 为懒加载: 未载入的类无法反射, 故先由「目录 + 文件名」推出类名, 再经 `class_exists` 载入
 *
 * 扫描规则(须同时满足, 任一条不满足则该文件被跳过):
 * 1. 文件位于 `{app.path}/{app}/controller/{Controller}.php`
 * 2. 文件名即类名(大小写须一致), 命名空间为 `app\{app}\controller`
 * 3. 类可被自动加载(`class_exists` 成立)
 *
 * 仅扫描 `controller` 目录本身, 不递归其子目录
 */
final class AttributeScanner {

    /**
     * 扫描应用目录下的控制器类
     *
     * @access public
     * @param string $appPath 应用根目录(其下每个子目录视为一个应用)
     * @return array<class-string> 控制器类名列表
     */
    public function scan(string $appPath): array {
        $classes=array();
        foreach((array)glob(rtrim($appPath,'/\\').'/*/controller/*.php') as $file) {
            $controller=basename($file,'.php');
            $app=basename(dirname($file,2));
            $class='app\\'.$app.'\\controller\\'.$controller;
            if(class_exists($class))
                $classes[]=$class;
        }
        return $classes;
    }

}
