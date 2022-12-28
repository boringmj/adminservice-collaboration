<?php

namespace AdminService;

use base\Container;
use AdminService\Exception;

final class App extends Container {

    /**
     * 初始化
     * 
     * @access public
     * @param array $classes 需要初始化的类
     * @return void
     */
    static public function init(array $classes=array()): void {
        $classes=array(
            'Router'=>$classes['Router']??Router::class,
            'View'=>$classes['View']??View::class,
            'Request'=>$classes['Request']??Request::class,
            'File'=>$classes['File']??File::class,
            'Exception'=>$classes['Exception']??Exception::class,
            'Cookie'=>$classes['Cookie']??Cookie::class,
            'Config'=>$classes['Config']??Config::class,
            'Log'=>$classes['Log']??Log::class
        );
        // 遍历类是否存在
        foreach($classes as $class)
            if(!class_exists($class))
                throw new Exception('Class "'.$class.'" not found.');
        parent::$class_container=$classes;
    }

    /**
     * 获取当前应用名称
     * 
     * @access public
     * @return string
     */
    static public function getAppName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['app']??null;
        return null;
    }

    /**
     * 获取当前控制器名称
     */
    static public function getControllerName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['controller']??null;
        return null;
    }

    /**
     * 获取当前方法名称
     */
    static public function getMethodName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['method']??null;
        return null;
    }

    /**
     * 初始化路由信息
     * 
     * @access private
     * @return void
     */
    static private function initRouteInfo(): void {
        // 检查是否存在缓存
        if(self::getData('route_info')===null) {
            // 获取路由信息
            $route_info=parent::get('Router')->getRouteInfo();
            // 缓存路由信息
            parent::setData('route_info',$route_info);
        }
    }

}

?>