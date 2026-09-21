<?php

namespace AdminService\Router;

use AdminService\Exception;

use function basename;
use function class_exists;
use function dirname;
use function glob;
use function interface_exists;
use function rtrim;
use function trait_exists;

/**
 * 属性路由扫描器
 *
 * - 按约定在应用目录下发现控制器类, 交给 {@see Router::registerAttributes()} 注册 `#[Route]`
 * - PHP 为懒加载: 未载入的类无法反射, 故先由「目录 + 文件名」推出类名, 再经 `class_exists` 载入
 *
 * 扫描规则:
 * 1. 文件位于 `{app.path}/{app}/controller/{Controller}.php`
 * 2. 文件名即类名(大小写须一致), 命名空间为 `app\{app}\controller`
 * 3. 推出的类名须可被自动加载; 无法加载且不是 trait/interface 时**抛出异常**
 *    (静默跳过会让路由凭空消失, 极难排查; 该情形通常是文件名与类名不一致)
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
     * @throws Exception 文件名与类名不一致
     */
    public function scan(string $appPath): array {
        $classes=array();
        foreach((array)glob(rtrim($appPath,'/\\').'/*/controller/*.php') as $file) {
            $controller=basename($file,'.php');
            $app=basename(dirname($file,2));
            $class='app\\'.$app.'\\controller\\'.$controller;
            if(class_exists($class)) {
                $classes[]=$class;
                continue;
            }
            // trait/interface 文件不参与路由注册, 但属正常文件, 不报错
            if(trait_exists($class)||interface_exists($class))
                continue;
            throw new Exception('Controller class not found.',-418,array(
                'file'=>$file,
                'expected'=>$class
            ));
        }
        return $classes;
    }

}
