<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Cache\CompileStore;
use AdminService\Config\Repository;
use AdminService\Router\RouterFactory;
use Tests\Fixtures\RouteCacheController;
use Tests\Fixtures\RouteCacheMiddleware;

use function clearstatcache;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

/**
 * 路由编译缓存测试
 *
 * - 命中时不加载路由文件
 * - 指纹变化、配置上下文变化、缓存损坏时回退重建
 * - 闭包路由与关闭编译缓存时不写缓存
 */
class RouteCacheTest extends TestCase {

    /**
     * 临时目录
     * @var string
     */
    private string $dir;

    /**
     * 默认路由文件
     * @var string
     */
    private string $route_file;

    /**
     * 应用目录
     * @var string
     */
    private string $app_dir;

    /**
     * 每个用例前创建隔离目录
     *
     * @return void
     */
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/adminservice_route_cache_'.uniqid('',true);
        mkdir($this->dir,0755,true);
        $this->route_file=$this->dir.'/routes.php';
        $this->app_dir=$this->dir.'/app';
        mkdir($this->app_dir,0755,true);
        $GLOBALS['route_cache_builds']=0;
    }

    /**
     * 每个用例后清理临时目录
     *
     * @return void
     */
    protected function tearDown(): void {
        unset($GLOBALS['route_cache_builds']);
        $this->removePath($this->dir);
    }

    /**
     * 构造测试配置
     *
     * @param bool $debug 是否调试模式
     * @param string $mode 编译缓存模式
     * @param string|null $route_file 路由文件(null = 默认文件)
     * @param array<string> $skip_on_debug debug + auto 跳过的缓存名
     * @return Repository
     */
    private function config(bool $debug=true,string $mode='always',?string $route_file=null,array $skip_on_debug=array('config')): Repository {
        return new Repository(array(
            'app'=>array(
                'debug'=>$debug,
                'path'=>$this->app_dir
            ),
            'cache'=>array(
                'path'=>$this->dir.'/cache',
                'dir_mode'=>0755,
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>$mode,
                    'skip_on_debug'=>$skip_on_debug,
                    'config'=>true,
                    'route'=>true,
                    'container'=>true
                )
            ),
            'route'=>array(
                'files'=>array($route_file??$this->route_file),
                'attributes'=>array('scan'=>false,'classes'=>array())
            ),
            'middlewares'=>array('controller'=>array())
        ));
    }

    /**
     * 默认路由定义
     *
     * @return string
     */
    private function defaultBody(): string {
        return '$router->get("/cached",array(\\Tests\\Fixtures\\RouteCacheController::class,"index"))->name("cached");'."\n"
            .'$router->get("/item/{id}",array(\\Tests\\Fixtures\\RouteCacheController::class,"show"))->name("item.show");';
    }

    /**
     * 写入默认路由文件
     *
     * @param string|null $body 路由定义(null = 默认定义)
     * @return void
     */
    private function writeRoutes(?string $body=null): void {
        $this->writeRouteFile($this->route_file,$body??$this->defaultBody());
    }

    /**
     * 写入指定路由文件
     *
     * @param string $file 文件
     * @param string $body 路由定义
     * @return void
     */
    private function writeRouteFile(string $file,string $body): void {
        $content="<?php\n\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."    \$GLOBALS['route_cache_builds']++;\n"
            .$body."\n};\n";
        file_put_contents($file,$content);
        clearstatcache(true,$file);
    }

    /**
     * 递归删除临时目录
     *
     * @param string $path 文件或目录
     * @return void
     */
    private function removePath(string $path): void {
        if(is_link($path)||is_file($path)) {
            @unlink($path);
            return;
        }
        if(!is_dir($path))
            return;
        $entries=scandir($path);
        if($entries!==false) {
            foreach($entries as $entry) {
                if($entry==='.'||$entry==='..')
                    continue;
                $this->removePath($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }

    /**
     * 测试: 未注入配置时 CompileStore 使用门面配置
     *
     * @return void
     */
    public function testCompileStoreFallsBackToFacadeConfig(): void {
        $store=new CompileStore();

        $this->assertContains($store->mode(),array('auto','always','off'));
        $this->assertStringEndsWith('/cache/compile/routes.cache.php',str_replace('\\','/',$store->path('routes')));
    }

    /**
     * 测试: 命中编译缓存时不执行路由文件
     *
     * @return void
     */
    public function testCacheHitAvoidsRouteFileExecution(): void {
        $this->writeRoutes();
        $config=$this->config(true,'always');
        $router=(new RouterFactory())->get($config);

        $this->assertSame(1,$GLOBALS['route_cache_builds']);
        $this->assertFileExists($this->dir.'/cache/compile/routes.cache.php');
        $this->assertCount(2,$router->getRoutes());

        $found=$router->find('GET','/item/42');
        $this->assertNotNull($found);
        $this->assertSame(array('id'=>'42'),$found[1]);
        $this->assertSame(array('id'=>'value'),$found[0]->getBindings());
        $this->assertSame(array(RouteCacheMiddleware::class),$found[0]->getCompiledControllerMiddlewares());

        $second=(new RouterFactory())->get($config);
        $this->assertSame(1,$GLOBALS['route_cache_builds'],'命中缓存时不得再次加载路由文件');
        $this->assertCount(2,$second->getRoutes());
        $this->assertNotNull($second->find('GET','/cached'));
        $this->assertNotNull($second->find('GET','/item/7'));
        $this->assertSame('/item/7',$second->url('item.show',array('id'=>7)));
    }

    /**
     * 测试: debug + auto 下路由缓存仍可命中
     *
     * @return void
     */
    public function testDebugAutoStillUsesRouteCache(): void {
        $this->writeRoutes();
        $config=$this->config(true,'auto');

        (new RouterFactory())->get($config);
        $this->assertSame(1,$GLOBALS['route_cache_builds']);
        $this->assertFileExists($this->dir.'/cache/compile/routes.cache.php');

        $second=(new RouterFactory())->get($config);
        $this->assertSame(1,$GLOBALS['route_cache_builds'],'debug 默认只跳过配置缓存, 路由缓存应继续命中');
        $this->assertNotNull($second->find('GET','/cached'));
    }

    /**
     * 测试: 按公开名称 route 跳过路由缓存
     *
     * @return void
     */
    public function testDebugSkipRouteByPublicName(): void {
        $this->writeRoutes();
        $config=$this->config(true,'auto',null,array('route'));

        (new RouterFactory())->get($config);
        $this->assertSame(1,$GLOBALS['route_cache_builds']);
        $this->assertFileDoesNotExist($this->dir.'/cache/compile/routes.cache.php');

        (new RouterFactory())->get($config);
        $this->assertSame(2,$GLOBALS['route_cache_builds'],'跳过路由缓存后每次都应实时装配');
    }

    /**
     * 测试: 开发模式指纹变化后重建
     *
     * @return void
     */
    public function testRouteFileChangeRebuildsWithFingerprint(): void {
        $this->writeRoutes();
        $config=$this->config(true,'auto');
        $first=(new RouterFactory())->get($config);
        $this->assertSame(1,$GLOBALS['route_cache_builds']);
        $this->assertNull($first->find('GET','/extra'));

        $this->writeRoutes($this->defaultBody()."\n".'$router->get("/extra",array(\\Tests\\Fixtures\\RouteCacheController::class,"index"))->name("extra");');
        $second=(new RouterFactory())->get($config);
        $this->assertSame(2,$GLOBALS['route_cache_builds'],'源文件变化后应重建');
        $this->assertNotNull($second->find('GET','/extra'));
    }

    /**
     * 测试: 生产零探测模式在源文件删除后仍命中缓存
     *
     * @return void
     */
    public function testControllerSourceChangeRebuildsRouteCache(): void {
        $class='RouteCacheTempController'.uniqid();
        $controller_file=$this->dir.'/'.$class.'.php';
        file_put_contents(
            $controller_file,
            "<?php\n\nnamespace Tests\\Fixtures;\n\nclass ".$class." {\n"
            ."    public function index(): string { return 'ok'; }\n}\n"
        );
        clearstatcache(true,$controller_file);
        $body='require_once '.var_export($controller_file,true).";\n"
            .'$router->get(\'/temp\',array(\\Tests\\Fixtures\\'.$class.'::class,\'index\'))->name(\'temp\');';
        $this->writeRoutes($body);

        $config=$this->config(true,'always');
        $first=(new RouterFactory())->get($config);
        $this->assertSame(1,$GLOBALS['route_cache_builds']);
        $this->assertNotNull($first->find('GET','/temp'));

        file_put_contents($controller_file,"\n// changed\n",FILE_APPEND);
        clearstatcache(true,$controller_file);

        $second=(new RouterFactory())->get($config);
        $this->assertSame(2,$GLOBALS['route_cache_builds'],'控制器文件变化应使路由缓存失效');
        $this->assertNotNull($second->find('GET','/temp'));
    }

    /**
     * 测试: 继承动作的父类文件变化后路由缓存失效
     *
     * @return void
     */
    public function testInheritedControllerSourceChangeRebuildsRouteCache(): void {
        $suffix=uniqid();
        $parent='RouteCacheParent'.$suffix;
        $child='RouteCacheChild'.$suffix;
        $parent_file=$this->dir.'/'.$parent.'.php';
        $child_file=$this->dir.'/'.$child.'.php';
        file_put_contents(
            $parent_file,
            "<?php\n\nnamespace Tests\\Fixtures;\n\nclass ".$parent." {\n"
            ."    public function index(): string { return 'ok'; }\n}\n"
        );
        file_put_contents(
            $child_file,
            "<?php\n\nnamespace Tests\\Fixtures;\n\nclass ".$child.' extends '.$parent." {}\n"
        );
        clearstatcache(true,$parent_file);
        clearstatcache(true,$child_file);
        $body='require_once '.var_export($parent_file,true).";\n"
            .'require_once '.var_export($child_file,true).";\n"
            .'$router->get(\'/inherited\',array(\\Tests\\Fixtures\\'.$child.'::class,\'index\'));';
        $this->writeRoutes($body);

        $config=$this->config(true,'always');
        (new RouterFactory())->get($config);
        $this->assertSame('valid',(new RouterFactory())->inspect($config)['status']);

        file_put_contents($parent_file,"\n// changed\n",FILE_APPEND);
        clearstatcache(true,$parent_file);

        $this->assertSame(
            'stale',
            (new RouterFactory())->inspect($config)['status'],
            '父类动作文件变化应使路由缓存失效'
        );
    }

    public function testZeroStatReusesCacheWhenSourceRemoved(): void {
        $this->writeRoutes();
        $config=$this->config(false,'auto');
        (new RouterFactory())->get($config);
        unlink($this->route_file);

        $router=(new RouterFactory())->get($config);
        $this->assertSame(1,$GLOBALS['route_cache_builds']);
        $this->assertNotNull($router->find('GET','/cached'));
    }

    /**
     * 测试: 零探测模式仍按配置上下文区分缓存
     *
     * @return void
     */
    public function testZeroStatContextSeparatesRouteFiles(): void {
        $this->writeRoutes();
        (new RouterFactory())->get($this->config(false,'auto'));

        $other=$this->dir.'/other.php';
        $this->writeRouteFile($other,'$router->get("/other",array(\\Tests\\Fixtures\\RouteCacheController::class,"index"))->name("other");');
        $router=(new RouterFactory())->get($this->config(false,'auto',$other));

        $this->assertSame(2,$GLOBALS['route_cache_builds'],'路由文件列表变化后应重建');
        $this->assertNotNull($router->find('GET','/other'));
    }

    /**
     * 测试: 含闭包的路由不写缓存
     *
     * @return void
     */
    public function testClosureRouteSkipsCache(): void {
        $this->writeRoutes('$router->get("/closure",function(): string { return "closure"; });');
        $router=(new RouterFactory())->get($this->config(true,'always'));

        $this->assertSame(1,$GLOBALS['route_cache_builds']);
        $this->assertNotNull($router->find('GET','/closure'));
        $this->assertFileDoesNotExist($this->dir.'/cache/compile/routes.cache.php');
    }

    /**
     * 测试: 损坏缓存回退重建
     *
     * @return void
     */
    public function testCorruptCacheFallsBackAndRewrites(): void {
        $this->writeRoutes();
        $config=$this->config(false,'auto');
        (new RouterFactory())->get($config);
        $cache=$this->dir.'/cache/compile/routes.cache.php';
        file_put_contents($cache,'<?php broken');

        $router=(new RouterFactory())->get($config);
        $this->assertSame(2,$GLOBALS['route_cache_builds'],'损坏缓存应回退重建');
        $this->assertNotNull($router->find('GET','/cached'));
        $this->assertStringContainsString("'payload'",(string)file_get_contents($cache));
    }

    /**
     * 测试: 属性路由扫描结果可缓存
     *
     * @return void
     */
    public function testAttributeScanCacheHit(): void {
        $config=new Repository(array(
            'app'=>array(
                'debug'=>false,
                'path'=>dirname(__DIR__).'/AdminService/app'
            ),
            'cache'=>array(
                'path'=>$this->dir.'/cache-scan',
                'dir_mode'=>0755,
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'auto',
                    'config'=>true,
                    'route'=>true,
                    'container'=>true
                )
            ),
            'route'=>array(
                'files'=>array(),
                'attributes'=>array('scan'=>true,'classes'=>array())
            ),
            'middlewares'=>array('controller'=>array())
        ));

        $router=(new RouterFactory())->get($config);
        $found=$router->find('GET','/index/index');
        $this->assertNotNull($found);
        $this->assertSame('index.index',$found[0]->getName());
        $this->assertFileExists($this->dir.'/cache-scan/compile/routes.cache.php');

        $second=(new RouterFactory())->get($config);
        $again=$second->find('GET','/index/index');
        $this->assertNotNull($again);
        $this->assertSame($found[0]->getHandler(),$again[0]->getHandler());
    }

    /**
     * 测试: 关闭编译缓存时每次实时装配
     *
     * @return void
     */
    public function testCompileOffAlwaysBuilds(): void {
        $this->writeRoutes();
        $config=$this->config(true,'off');
        (new RouterFactory())->get($config);
        (new RouterFactory())->get($config);

        $this->assertSame(2,$GLOBALS['route_cache_builds']);
        $this->assertFileDoesNotExist($this->dir.'/cache/compile/routes.cache.php');
    }

}
