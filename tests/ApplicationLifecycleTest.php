<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Application;
use AdminService\ArraySession;
use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\ContextStorage;
use AdminService\Error;
use AdminService\HttpRequest;
use AdminService\RequestContext;
use AdminService\Response;
use AdminService\Runtime;
use base\AbstractSession;
use base\Request;
use Fiber;
use ReflectionProperty;
use Tests\Fixtures\ConcreteService;

use function count;
use function error_reporting;
use function sys_get_temp_dir;

/**
 * 请求生命周期测试
 *
 * - 连续请求不共享请求对象
 * - 父容器中的请求对象不被请求装配复用
 * - 请求内禁止替换配置仓储
 * - Error 状态在请求边界复位
 */
class ApplicationLifecycleTest extends TestCase {

    /**
     * 每个用例使用干净的应用容器
     *
     * @return void
     */
    protected function setUp(): void {
        load_test_config();
        App::setInstance(new Container());
        App::init();
        Error::reset();
        Error::setContainer(null);
        ContextStorage::reset();
    }

    /**
     * 清理请求与错误状态
     *
     * @return void
     */
    protected function tearDown(): void {
        Error::reset();
        Error::setContainer(null);
        ContextStorage::reset();
        Runtime::reset();
        parent::tearDown();
    }

    /**
     * 测试: 连续两次 handle 不共享 Request/Response/Session
     *
     * @return void
     */
    public function testConsecutiveHandlesDoNotShareRequestObjects(): void {
        $application=new Application();
        $first=array();
        $second=array();

        $application->handle(function() use (&$first): void {
            $first=array(
                App::get(Request::class),
                App::get(Response::class),
                App::get(AbstractSession::class)
            );
        });
        $application->handle(function() use (&$second): void {
            $second=array(
                App::get(Request::class),
                App::get(Response::class),
                App::get(AbstractSession::class)
            );
        });

        $this->assertNotSame($first[0],$second[0]);
        $this->assertNotSame($first[1],$second[1]);
        $this->assertNotSame($first[2],$second[2]);
    }

    /**
     * 测试: 父容器里的请求对象不会被请求装配复用
     *
     * @return void
     */
    public function testParentRequestInstancesAreIgnored(): void {
        $application=new Application();
        $parent_request=new HttpRequest(array('server'=>array(
            'REQUEST_URI'=>'/parent',
            'REQUEST_METHOD'=>'GET'
        )));
        $parent_response=new Response();
        $parent_session=new ArraySession();
        $application->container()->instance(Request::class,$parent_request);
        $application->container()->instance(Response::class,$parent_response);
        $application->container()->instance(AbstractSession::class,$parent_session);

        $application->handle(function() use ($parent_request,$parent_response,$parent_session): void {
            $this->assertNotSame($parent_request,App::get(Request::class));
            $this->assertNotSame($parent_response,App::get(Response::class));
            $this->assertNotSame($parent_session,App::get(AbstractSession::class));
        });
    }

    /**
     * 测试: 测试入口种子对象只用于下一次请求
     *
     * @return void
     */
    public function testSeededRequestIsUsedForNextHandle(): void {
        $application=new Application();
        $request=new HttpRequest(array('server'=>array(
            'REQUEST_URI'=>'/seeded',
            'REQUEST_METHOD'=>'GET'
        )));
        $response=new Response();
        $application->seedRequest($request,$response);

        $application->handle(function() use ($request,$response): void {
            $this->assertSame($request,App::get(Request::class));
            $this->assertSame($response,App::get(Response::class));
        });
    }

    /**
     * 测试: 请求内 setValue 只修改请求配置副本
     *
     * @return void
     */
    public function testRequestConfigChangesDoNotLeak(): void {
        $application=new Application();
        $outside=Config::repository();
        $inside=null;

        $application->handle(function() use (&$inside): void {
            $inside=Config::repository();
            Config::setValue('app.debug',true);
            $this->assertTrue((bool)Config::get('app.debug'));
        });
        $this->assertNotSame($outside,$inside);

        $application->handle(function(): void {
            $this->assertFalse((bool)Config::get('app.debug'));
            Config::setValue('app.debug',true);
        });
        $this->assertFalse((bool)Config::get('app.debug'),'请求内修改不得泄漏到应用级配置');
    }

    /**
     * 测试: 嵌套请求以应用级配置为基准
     *
     * @return void
     */
    public function testNestedHandleUsesApplicationConfig(): void {
        $application=new Application();
        $application->handle(function() use ($application): void {
            Config::setValue('app.debug',true);
            $this->assertTrue((bool)Config::get('app.debug'));

            $application->handle(function(): void {
                $this->assertFalse((bool)Config::get('app.debug'));
            });

            $this->assertTrue((bool)Config::get('app.debug'));
        });
    }

    /**
     * 测试: 延迟落盘的请求失败时在请求边界落盘
     *
     * @return void
     */
    public function testFailedDeferredRequestFlushesMetadata(): void {
        $application=new Application();
        try {
            $application->handle(static function(): void {
                throw new \RuntimeException('boom');
            },true);
            $this->fail('异常应向外抛出');
        } catch(\RuntimeException) {
        }
        $property=new ReflectionProperty(Container::class,'defer_metadata_flush');
        $property->setAccessible(true);
        $this->assertFalse(
            (bool)$property->getValue($application->container()),
            '异常路径应结束延迟模式并落盘'
        );
    }

    /**
     * 测试: 嵌套请求不覆盖外层的错误记录
     *
     * @return void
     */
    public function testNestedHandleKeepsOuterErrors(): void {
        $application=new Application();
        $previous=error_reporting(E_ALL);
        try {
            $application->handle(function() use ($application): void {
                Error::handleError(E_USER_WARNING,'outer warning',__FILE__,1);
                $this->assertCount(1,Error::getErrors());

                $application->handle(function(): void {
                    $this->assertSame(array(),Error::getErrors(),'内层请求应从空状态开始');
                    Error::handleError(E_USER_WARNING,'inner warning',__FILE__,2);
                });

                $errors=Error::getErrors();
                $this->assertCount(1,$errors);
                $this->assertSame('outer warning',$errors[0]['message']);
            });
        } finally {
            Error::reset();
            error_reporting($previous);
        }
    }

    /**
     * 测试: 请求配置副本不能清空共享容器元数据
     *
     * - 请求容器与应用容器共享 `ContainerMetadata`
     * - 请求级 `cache.path` / debug 变化只影响请求配置, 不能改写共享元数据配置
     *
     * @return void
     */
    public function testRequestConfigDoesNotClearSharedMetadata(): void {
        $application=new Application();
        $metadata_property=new \ReflectionProperty(Container::class,'metadata');
        $metadata_property->setAccessible(true);
        $metadata=$metadata_property->getValue($application->container());
        $metadata->getClass(ConcreteService::class);
        $classes_property=new \ReflectionProperty($metadata,'classes');
        $classes_property->setAccessible(true);
        $before=count($classes_property->getValue($metadata));
        $this->assertGreaterThan(0,$before,'前置条件: 元数据已加载');

        $application->handle(function(): void {
            Config::setValue('cache.path',sys_get_temp_dir().'/adminservice_request_metadata');
        });

        $after=count($classes_property->getValue($metadata));
        $this->assertSame($before,$after,'请求配置登记不应清空共享元数据');

        $config_property=new \ReflectionProperty($metadata,'config');
        $config_property->setAccessible(true);
        $this->assertSame(Config::repository(),$config_property->getValue($metadata),'共享元数据必须继续使用应用级配置');
    }

    /**
     * 测试: 隔离执行流的顶层请求结束后恢复应用级容器
     *
     * @return void
     */
    public function testFiberHandleRestoresApplicationContainer(): void {
        $application=new Application();
        $app_container=$application->container();
        $custom=new Container();
        App::setInstance($custom);
        try {
            $fiber=new Fiber(function() use ($application,$app_container): void {
                $application->handle(function(): void {
                });
                $this->assertSame($app_container,App::getInstance());
            });
            $fiber->start();
            $this->assertTrue($fiber->isTerminated());
        } finally {
            App::setInstance($app_container);
        }
    }

    /**
     * 测试: 请求异常不污染下一次请求
     *
     * @return void
     */
    public function testRequestExceptionDoesNotPoisonNextRequest(): void {
        $application=new Application();
        try {
            $application->handle(function(): void {
                throw new \RuntimeException('request failed');
            });
            $this->fail('请求异常应向上抛出');
        } catch(\RuntimeException $e) {
            $this->assertSame('request failed',$e->getMessage());
        }

        $application->handle(function() use ($application): void {
            $this->assertNotNull($application->requestContext());
            $this->assertSame(array(),Error::getErrors());
            $this->assertNotNull(App::get(Request::class));
        });
    }

    /**
     * 测试: 请求内禁止替换整份配置仓储
     *
     * @return void
     */
    public function testConfigRepositoryCannotBeReplacedInsideRequest(): void {
        $application=new Application();
        $original=Config::repository();

        try {
            $application->handle(function(): void {
                Config::setRepository(new Repository(array('k'=>'request')));
            });
            $this->fail('请求内替换配置仓储应抛 ConfigException');
        } catch(\AdminService\exception\ConfigException) {
            // 预期
        }

        $this->assertSame($original,Config::repository());
    }

    /**
     * 测试: Error 收集状态在下一请求开始时复位
     *
     * @return void
     */
    public function testErrorStateResetsBetweenRequests(): void {
        $application=new Application();
        $application->handle(function(): void {
            $previous=error_reporting(E_ALL);
            try {
                Error::handleError(E_USER_WARNING,'first request warning',__FILE__,__LINE__);
            } finally {
                error_reporting($previous);
            }
        });
        $this->assertCount(1,Error::getErrors());

        $application->handle(function(): void {
            $this->assertSame(array(),Error::getErrors());
        });
    }

    /**
     * 测试: endRequest 释放请求上下文与错误引用
     *
     * @return void
     */
    public function testEndRequestReleasesRequestContext(): void {
        $application=new Application();
        $application->handle(function() use ($application): void {
            $this->assertNotNull($application->requestContext());
        });
        $context=$application->requestContext();
        $this->assertNotNull($context);

        $application->endRequest($context);
        $this->assertNull($application->requestContext());
        $this->assertSame($application->container(),$application->requestContainer());
        $this->assertNull(Error::requestContext());
    }

    /**
     * 测试: 无参 endRequest 不操作其它请求的上下文
     *
     * @return void
     */
    public function testEndRequestWithoutContextLeavesOtherRequestAlone(): void {
        $application=new Application();
        $application->handle(function(): void {});
        $first=$application->requestContext();
        $application->handle(function(): void {});
        $second=$application->requestContext();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first,$second);

        $application->endRequest();
        $this->assertSame($second,$application->requestContext(),'无参调用不应关闭其它请求的上下文');

        $application->endRequest($second);
        $this->assertNull($application->requestContext());
    }

    /**
     * 测试: 嵌套 handle 结束后恢复外层门面与上下文
     *
     * @return void
     */
    public function testNestedHandleRestoresOuterContext(): void {
        $application=new Application();
        $application->handle(function() use ($application): void {
            $outer_container=App::getInstance();
            $outer_context=ContextStorage::current();
            $application->handle(function() use ($outer_container): void {
                $this->assertNotSame($outer_container,App::getInstance());
            });
            $this->assertSame($outer_container,App::getInstance());
            $this->assertSame($outer_context,ContextStorage::current());
        });
    }

    /**
     * 测试: 请求内登记的实例不会进入下一请求
     *
     * @return void
     */
    public function testRequestScopedRegistrationDoesNotLeak(): void {
        $application=new Application();
        $marker=new ConcreteService();
        $application->handle(function() use ($marker): void {
            App::instance(ConcreteService::class,$marker);
        });
        $application->handle(function(): void {
            $this->assertFalse(App::getInstance()->hasInstance(ConcreteService::class));
        });
    }

    /**
     * 测试: Fiber 交错请求的配置与上下文互不串用
     *
     * @return void
     */
    public function testFiberInterleavedRequestsKeepContextsSeparate(): void {
        $application=new Application();
        $seen=array();
        $make=function(string $key,bool $debug) use($application,&$seen): Fiber {
            return new Fiber(function() use($application,$key,$debug,&$seen): void {
                $context=$application->handle(function($container) use($application,$key,$debug,&$seen): RequestContext {
                    $container->get(Repository::class)->put('app.debug',$debug);
                    $request=$application->requestContext()?->request();
                    Fiber::suspend();
                    $this->assertSame($debug,Config::get('app.debug'));
                    $this->assertSame($request,$application->requestContext()?->request());
                    $seen[$key]=true;
                    return $container->get(RequestContext::class);
                },true,new HttpRequest(array('server'=>array(
                    'REQUEST_URI'=>'/',
                    'REQUEST_METHOD'=>'GET'
                ))));
                $application->endRequest($context);
            });
        };
        $first=$make('first',true);
        $second=$make('second',false);
        $first->start();
        $second->start();
        $this->assertFalse($first->isTerminated());
        $this->assertFalse($second->isTerminated());
        $first->resume();
        $second->resume();
        $this->assertTrue($first->isTerminated());
        $this->assertTrue($second->isTerminated());
        $this->assertSame(array('first'=>true,'second'=>true),$seen);
    }

}
