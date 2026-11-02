<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\ContainerMetadata;
use AdminService\ReflectionCache;
use Tests\Fixtures\ConfigConsumer;
use Tests\Fixtures\UserInfo;
use Tests\Fixtures\UserProfile;

use function array_column;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

/**
 * 容器反射元数据编译缓存测试
 *
 * - 无注解类不产生注入项
 * - 属性 / Setter / 生命周期方法 / 单依赖方法元数据可命中
 * - 方法参数描述符可命中, 容器装配行为不变
 */
class ContainerCacheTest extends TestCase {

    /**
     * 临时目录
     * @var string
     */
    private string $dir;

    /**
     * 测试配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 每个用例前创建隔离目录与配置
     *
     * @return void
     */
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/adminservice_container_cache_'.uniqid('',true);
        mkdir($this->dir,0755,true);
        $this->config=new Repository(array(
            'app'=>array('debug'=>false),
            'data'=>array('ext_name'=>'txt','name_cycle'=>3600),
            'log'=>array('path'=>'test-log'),
            'cache'=>array(
                'path'=>$this->dir.'/cache',
                'dir_mode'=>0755,
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'always',
                    'config'=>true,
                    'route'=>true,
                    'container'=>true
                )
            )
        ));
    }

    /**
     * 每个用例后清理临时目录
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->removePath($this->dir);
    }

    /**
     * 测试: 注解成员与方法参数写入容器元数据
     *
     * @return void
     */
    public function testMetadataCollectsAnnotatedMembersAndParams(): void {
        $metadata=new ContainerMetadata(new ReflectionCache(),$this->config);

        $userInfo=$metadata->getClass(UserInfo::class);
        $this->assertEqualsCanonicalizing(array('name','status','id'),$userInfo['properties']);
        $this->assertSame(array(),$userInfo['methods']);

        $profile=$metadata->getClass(UserProfile::class);
        $this->assertSame(array(),$profile['properties']);
        $this->assertEqualsCanonicalizing(array('init','boot'),$profile['methods']);

        $consumer=$metadata->getClass(ConfigConsumer::class);
        $this->assertContains('ext_name',$consumer['properties']);
        $this->assertContains('setLogPath',$consumer['setters']);

        $params=$metadata->methodParams(new \ReflectionMethod(ConfigConsumer::class,'methodParamInjected'));
        $this->assertSame(array('ext','explicit'),array_column($params,'name'));
        $this->assertSame('data.ext_name',$params[0]['config']['key']);
        $this->assertSame(array('string'),$params[0]['types']);

        $cached=include $this->dir.'/cache/compile/container.cache.php';
        $this->assertSame(array(),$cached['payload']['classes'],'首次加载不应因扫描单个类就写入类元数据');
        $metadata->flush();
        $this->assertFileExists($this->dir.'/cache/compile/container.cache.php');
        $cached=include $this->dir.'/cache/compile/container.cache.php';
        $this->assertArrayHasKey(UserInfo::class,$cached['payload']['classes']);
    }

    /**
     * 测试: 关闭容器编译缓存时不写文件
     *
     * @return void
     */
    public function testContainerCacheOffDoesNotWrite(): void {
        $config=new Repository(array(
            'app'=>array('debug'=>false),
            'cache'=>array(
                'path'=>$this->dir.'/cache-off',
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'off',
                    'container'=>true
                )
            )
        ));
        $metadata=new ContainerMetadata(new ReflectionCache(),$config);
        $metadata->getClass(UserInfo::class);
        $metadata->flush();

        $this->assertFileDoesNotExist($this->dir.'/cache-off/compile/container.cache.php');
    }

    /**
     * 测试: 新的元数据实例可从缓存恢复
     *
     * @return void
     */
    public function testMetadataReloadsFromCache(): void {
        $first=new ContainerMetadata(new ReflectionCache(),$this->config);
        $expected=$first->getClass(UserInfo::class);
        $first->flush();

        $second=new ContainerMetadata(new ReflectionCache(),$this->config);
        $this->assertSame($expected,$second->getClass(UserInfo::class));
        $this->assertFileExists($this->dir.'/cache/compile/container.cache.php');
    }

    /**
     * 测试: 同一配置下复用源文件校验结果
     *
     * @return void
     */
    public function testMetadataCachesSourceValidationMode(): void {
        $config=new class($this->dir.'/cache-spy') implements \base\ConfigInterface {
            private string $cache_path;
            public int $reads=0;

            public function __construct(string $cache_path) {
                $this->cache_path=$cache_path;
            }

            public function get(string $key,mixed $default=null): mixed {
                $this->reads++;
                return match($key) {
                    'app.debug'=>true,
                    'cache.path'=>$this->cache_path,
                    'cache.compile.enabled'=>true,
                    'cache.compile.mode'=>'always',
                    'cache.compile.container'=>true,
                    default=>$default
                };
            }

            public function has(string $key): bool {
                return false;
            }

            public function all(): array {
                return array();
            }
        };

        $metadata=new ContainerMetadata(new ReflectionCache(),$config);
        $metadata->getClass(UserInfo::class);
        $metadata->getClass(UserInfo::class);
        $reads=$config->reads;
        $metadata->getClass(UserInfo::class);

        $this->assertSame($reads,$config->reads,'同一配置下不应重复解析源文件校验模式');
    }

    /**
     * 测试: auto + 非 debug 直接信任已加载元数据, 不探测源文件
     *
     * @return void
     */
    public function testAutoProductionTrustsCachedMetadataWithoutSourceStat(): void {
        $short='ContainerCacheTemp'.uniqid();
        $class='Tests\\CacheTemp\\'.$short;
        $source=$this->dir.'/'.$short.'.php';
        file_put_contents(
            $source,
            "<?php\nnamespace Tests\\CacheTemp;\n\nuse base\\Attribute\\AutowireProperty;\n\nclass {$short} {\n    #[AutowireProperty(\\stdClass::class)]\n    public mixed \$dependency=null;\n}\n"
        );
        require $source;

        $config=new Repository(array(
            'app'=>array('debug'=>false),
            'cache'=>array(
                'path'=>$this->dir.'/cache-auto',
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'auto',
                    'container'=>true
                )
            )
        ));
        $first=new ContainerMetadata(new ReflectionCache(),$config);
        $first->getClass($class);
        $first->flush();

        $cache_file=$this->dir.'/cache-auto/compile/container.cache.php';
        $cached=include $cache_file;
        $cached['payload']['classes'][$class]['properties']=array('sentinel');
        file_put_contents($cache_file,"<?php\n\nreturn ".var_export($cached,true).";\n");
        unlink($source);

        $second=new ContainerMetadata(new ReflectionCache(),$config);
        $this->assertSame(array('sentinel'),$second->getClass($class)['properties']);
    }

    /**
     * 测试: 校验模式在源文件变化后重建元数据
     *
     * @return void
     */
    public function testValidationModeRebuildsWhenSourceChanges(): void {
        $short='ContainerCacheValidation'.uniqid();
        $class='Tests\\CacheTemp\\'.$short;
        $source=$this->dir.'/'.$short.'.php';
        file_put_contents(
            $source,
            "<?php\nnamespace Tests\\CacheTemp;\n\nuse base\\Attribute\\AutowireProperty;\n\nclass {$short} {\n    #[AutowireProperty(\\stdClass::class)]\n    public mixed \$dependency=null;\n}\n"
        );
        require $source;

        $config=new Repository(array(
            'app'=>array('debug'=>false),
            'cache'=>array(
                'path'=>$this->dir.'/cache-validation',
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'always',
                    'container'=>true
                )
            )
        ));
        $first=new ContainerMetadata(new ReflectionCache(),$config);
        $first->getClass($class);
        $first->flush();

        $cache_file=$this->dir.'/cache-validation/compile/container.cache.php';
        $cached=include $cache_file;
        $cached['payload']['classes'][$class]['properties']=array('sentinel');
        file_put_contents($cache_file,"<?php\n\nreturn ".var_export($cached,true).";\n");
        file_put_contents($source,"\n",FILE_APPEND);

        $second=new ContainerMetadata(new ReflectionCache(),$config);
        $this->assertSame(array('dependency'),$second->getClass($class)['properties']);
    }

    /**
     * 测试: fork 共享容器元数据, 避免每次请求重新 include/stat
     *
     * @return void
     */
    public function testForkSharesContainerMetadata(): void {
        $container=new Container();
        $child=$container->fork();
        $property=new \ReflectionProperty(Container::class,'metadata');
        $property->setAccessible(true);

        $this->assertSame($property->getValue($container),$property->getValue($child));
    }

    /**
     * 测试: 容器装配仍按元数据完成
     *
     * @return void
     */
    public function testContainerUsesMetadataWithoutBehaviorChange(): void {
        $container=new Container();
        $container->instance(\base\ConfigInterface::class,$this->config);
        $consumer=$container->make(ConfigConsumer::class);

        $this->assertSame(3600,$consumer->cycle);
        $this->assertSame('txt',$consumer->ext_name);
        $this->assertFileExists($this->dir.'/cache/compile/container.cache.php');
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

}
