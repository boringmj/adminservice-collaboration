<?php

namespace AdminService\Router;

use base\ConfigInterface;

use function glob;
use function is_dir;
use function rtrim;
use function sort;

/**
 * 应用级路由表提供器
 *
 * - 路由文件 / `#[Route]` 属性声明只装配一次, 后续请求复用同一个 `Router`
 * - 以**配置对象身份**作为失效条件: 运行期 `Config::setRepository()` 换了一整份配置时重建;
 *   同一份配置对象内的值变更不触发重建(路由配置按引导期处理)
 * - 路由注册项应保持请求无关: 处理器用类名/闭包均可, 但闭包不要捕获请求对象
 *
 * @access public
 * @package AdminService\Router
 */
final class RouterFactory {

    /**
     * 已装配的路由表
     * @var Router|null
     */
    private ?Router $router=null;

    /**
     * 当前路由表对应的配置对象
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 获取路由表(首次或配置对象替换时装配)
     *
     * @access public
     * @param ConfigInterface $config 当前配置(由调用方传入, 避免提供器自己摸门面)
     * @return Router
     * @throws \AdminService\Exception
     */
    public function get(ConfigInterface $config): Router {
        if($this->router!==null&&$this->config===$config)
            return $this->router;
        $router=new Router();
        foreach((array)$config->get('route.files',array()) as $path)
            $this->loadRoutes($router,$path);
        // 属性路由: 自动扫描 + 手工登记
        $attributes=$config->get('route.attributes',array());
        if(!empty($attributes['scan']))
            $router->registerAttributes((new AttributeScanner())->scan((string)$config->get('app.path')));
        if(!empty($attributes['classes']))
            $router->registerAttributes($attributes['classes']);
        // 装配完成后校验路由名唯一, 让重名尽早暴露
        $router->assertNamesUnique();
        $this->router=$router;
        $this->config=$config;
        return $router;
    }

    /**
     * 加载路由文件或目录
     *
     * - 目录加载其中全部 `.php`, 按文件名排序保证注册顺序稳定
     *
     * @access private
     * @param Router $router 路由表
     * @param string $path 路由文件或目录路径
     * @return void
     * @throws \AdminService\Exception 路径不存在
     */
    private function loadRoutes(Router $router,string $path): void {
        if(is_dir($path)) {
            $files=(array)glob(rtrim($path,'/\\').'/*.php');
            sort($files);
            foreach($files as $file)
                $router->load($file);
            return;
        }
        $router->load($path);
    }

}
