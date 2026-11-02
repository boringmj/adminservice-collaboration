<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\ContainerMetadata;
use AdminService\ReflectionCache;
use Tests\Fixtures\ConfigConsumer;
use Tests\Fixtures\UserInfo;
use Tests\Fixtures\UserProfile;

use function array_column;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

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
     * 测试: 容器装配仍按元数据完成
     *
     * @return void
     */
    public function testContainerUsesMetadataWithoutBehaviorChange(): void {
        $old=Config::repository();
        try {
            Config::setRepository($this->config);
            $container=new Container();
            $container->instance(\base\ConfigInterface::class,$this->config);
            $consumer=$container->make(ConfigConsumer::class);

            $this->assertSame(3600,$consumer->cycle);
            $this->assertSame('txt',$consumer->ext_name);
            $this->assertFileExists($this->dir.'/cache/compile/container.cache.php');
        } finally {
            // Config 没有“清空仓库”的公开接口, 初始为空时恢复成空仓库, 避免临时配置泄漏
            Config::setRepository($old??new Repository(array()));
        }
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
