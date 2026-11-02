<?php

namespace AdminService;

use AdminService\Cache\CompileStore;
use AdminService\Runtime;
use base\Attribute\AutowireLifecycle;
use base\Attribute\AutowireMethod;
use base\Attribute\AutowireProperty;
use base\Attribute\AutowireSetter;
use base\Attribute\Config as ConfigAttribute;
use base\ConfigInterface;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function is_array;
use function is_string;
use function stat;
use function str_replace;

/**
 * 容器反射元数据缓存
 *
 * - 缓存类的自动装配成员名与方法参数描述符
 * - 单个类按源文件 stat 校验; 容器缓存文件由 `CompileStore` 管理
 * - 无注解类只记录空列表, 装配时不再扫描全部属性/方法
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class ContainerMetadata {

    /**
     * 缓存格式版本
     */
    private const VERSION=1;

    /**
     * 反射缓存
     * @var ReflectionCache
     */
    private ReflectionCache $reflections;

    /**
     * 编译缓存存储
     * @var CompileStore
     */
    private CompileStore $store;

    /**
     * 是否已加载磁盘缓存
     * @var bool
     */
    private bool $loaded=false;

    /**
     * 已加载的类元数据
     *
     * @var array<string,array{
     *     file:array{mtime:int,size:int}|null,
     *     properties:array<string>,
     *     setters:array<string>,
     *     methods:array<string>,
     *     params:array<string,array<array<string,mixed>>>
     * }>
     */
    private array $classes=array();

    /**
     * 构造方法
     *
     * @access public
     * @param ReflectionCache|null $reflections 反射缓存
     * @param ConfigInterface|null $config 配置契约(null = 使用门面配置)
     */
    public function __construct(?ReflectionCache $reflections=null,?ConfigInterface $config=null) {
        $this->reflections=$reflections??new ReflectionCache();
        $this->store=new CompileStore($config);
    }

    /**
     * 获取类的自动装配元数据
     *
     * @access public
     * @param class-string $class 类名
     * @return array{properties:array<string>,setters:array<string>,methods:array<string>}
     */
    public function getClass(string $class): array {
        $entry=$this->entry($class);
        return array(
            'properties'=>$entry['properties'],
            'setters'=>$entry['setters'],
            'methods'=>$entry['methods']
        );
    }

    /**
     * 获取方法参数描述符
     *
     * @access public
     * @param ReflectionMethod $method 方法
     * @return array<array<string,mixed>>
     */
    public function methodParams(ReflectionMethod $method): array {
        $class=$method->getDeclaringClass()->getName();
        $this->entry($class);
        $name=$method->getName();
        if(!isset($this->classes[$class]['params'][$name])
            ||!is_array($this->classes[$class]['params'][$name])) {
            $this->classes[$class]['params'][$name]=$this->buildParams($method);
            $this->save();
        }
        return $this->classes[$class]['params'][$name];
    }

    /**
     * 加载类条目(必要时重建并落盘)
     *
     * @access private
     * @param class-string $class 类名
     * @return array{
     *     file:array{mtime:int,size:int}|null,
     *     properties:array<string>,
     *     setters:array<string>,
     *     methods:array<string>,
     *     params:array<string,array<array<string,mixed>>>
     * }
     */
    private function entry(string $class): array {
        $this->load();
        $reflection=$this->reflections->getClass($class);
        $name=$reflection->getName();
        $file=$this->classFileStat($reflection);
        if(isset($this->classes[$name])
            &&$this->validEntry($this->classes[$name])
            &&$this->classes[$name]['file']===$file)
            return $this->classes[$name];
        $this->classes[$name]=$this->build($reflection,$file);
        $this->save();
        return $this->classes[$name];
    }

    /**
     * 读取容器编译缓存
     *
     * @access private
     * @return void
     */
    private function load(): void {
        if($this->loaded)
            return;
        $this->loaded=true;
        $payload=$this->store->load('container',function(): string {
            return (string)self::VERSION;
        },function(): array {
            return array('version'=>self::VERSION,'classes'=>array());
        },$this->context());
        if(is_array($payload)
            &&isset($payload['version'],$payload['classes'])
            &&(int)$payload['version']===self::VERSION
            &&is_array($payload['classes']))
            $this->classes=$payload['classes'];
    }

    /**
     * 判断缓存条目结构是否有效
     *
     * @access private
     * @param mixed $entry 缓存条目
     * @return bool
     */
    private function validEntry(mixed $entry): bool {
        return is_array($entry)
            &&array_key_exists('file',$entry)
            &&($entry['file']===null||is_array($entry['file']))
            &&isset($entry['properties'],$entry['setters'],$entry['methods'],$entry['params'])
            &&is_array($entry['properties'])
            &&is_array($entry['setters'])
            &&is_array($entry['methods'])
            &&is_array($entry['params']);
    }

    /**
     * 构建类元数据
     *
     * @access private
     * @param ReflectionClass<object> $reflection 类反射
     * @param array{mtime:int,size:int}|null $file 类文件指纹
     * @return array{
     *     file:array{mtime:int,size:int}|null,
     *     properties:array<string>,
     *     setters:array<string>,
     *     methods:array<string>,
     *     params:array<string,array<array<string,mixed>>>
     * }
     */
    private function build(ReflectionClass $reflection,?array $file): array {
        $properties=array();
        foreach($reflection->getProperties() as $property)
            if($this->hasAttribute($property,ConfigAttribute::class)
                ||$this->hasAttribute($property,AutowireProperty::class))
                $properties[]=$property->getName();

        $setters=array();
        $methods=array();
        foreach($reflection->getMethods() as $method) {
            $name=$method->getName();
            if($this->hasAttribute($method,ConfigAttribute::class)
                ||$this->hasAttribute($method,AutowireSetter::class))
                $setters[]=$name;
            if($this->hasAttribute($method,AutowireMethod::class)
                ||$this->hasAttribute($method,AutowireLifecycle::class))
                $methods[]=$name;
        }
        return array(
            'file'=>$file,
            'properties'=>$properties,
            'setters'=>$setters,
            'methods'=>$methods,
            'params'=>array()
        );
    }

    /**
     * 构建方法参数描述符
     *
     * @access private
     * @param ReflectionFunctionAbstract $function 方法
     * @return array<array<string,mixed>>
     */
    private function buildParams(ReflectionFunctionAbstract $function): array {
        $params=array();
        foreach($function->getParameters() as $parameter)
            $params[]=$this->parameterMeta($parameter);
        return $params;
    }

    /**
     * 构建单个参数描述符
     *
     * @access private
     * @param ReflectionParameter $parameter 参数
     * @return array<string,mixed>
     */
    private function parameterMeta(ReflectionParameter $parameter): array {
        $types=explode('|',(string)$parameter->getType());
        $types=array_map(function(string $type): string {
            return $this->standardType($type);
        },$types);
        if($parameter->allowsNull())
            $types[]='NULL';
        return array(
            'name'=>$parameter->getName(),
            'types'=>array_values(array_unique($types)),
            'allows_null'=>$parameter->allowsNull(),
            'is_variadic'=>$parameter->isVariadic(),
            'has_default'=>$parameter->isDefaultValueAvailable(),
            'default'=>$parameter->isDefaultValueAvailable()?$parameter->getDefaultValue():null,
            'config'=>$this->configMeta($parameter)
        );
    }

    /**
     * 构建 Config 注解元数据
     *
     * @access private
     * @param ReflectionProperty|ReflectionMethod|ReflectionParameter $target 目标
     * @return array{key:string,has_default:bool,default:mixed}|null
     */
    private function configMeta(ReflectionProperty|ReflectionMethod|ReflectionParameter $target): ?array {
        $attributes=$target->getAttributes(ConfigAttribute::class);
        if($attributes===array())
            return null;
        $config=$attributes[0]->newInstance();
        return array(
            'key'=>$config->getKey(),
            'has_default'=>$config->hasDefault(),
            'default'=>$config->getDefault()
        );
    }

    /**
     * 类型标准化(与 ArgumentResolver 的口径保持一致)
     *
     * @access private
     * @param string $type 类型
     * @return string
     */
    private function standardType(string $type): string {
        $type=str_replace('?','',$type);
        return match($type) {
            'int'=>'integer',
            'bool'=>'boolean',
            'float'=>'double',
            'null'=>'NULL',
            default=>$type
        };
    }

    /**
     * 判断目标是否有指定注解
     *
     * @access private
     * @param ReflectionProperty|ReflectionMethod $target 目标
     * @param class-string $attribute 注解类名
     * @return bool
     */
    private function hasAttribute(ReflectionProperty|ReflectionMethod $target,string $attribute): bool {
        return $target->getAttributes($attribute)!==array();
    }

    /**
     * 类文件指纹
     *
     * @access private
     * @param ReflectionClass<object> $reflection 类反射
     * @return array{mtime:int,size:int}|null
     */
    private function classFileStat(ReflectionClass $reflection): ?array {
        $file=$reflection->getFileName();
        if(!is_string($file)||$file==='')
            return null;
        $stat=@stat($file);
        if($stat===false)
            return null;
        return array(
            'mtime'=>(int)$stat['mtime'],
            'size'=>(int)$stat['size']
        );
    }

    /**
     * 保存容器编译缓存
     *
     * @access private
     * @return void
     */
    private function save(): void {
        if(!$this->store->enabled('container'))
            return;
        $this->store->save('container',(string)self::VERSION,array(
            'version'=>self::VERSION,
            'classes'=>$this->classes
        ),$this->context());
    }

    /**
     * 缓存上下文
     *
     * @access private
     * @return string
     */
    private function context(): string {
        return $this->store->hash(array(
            'runtime'=>Runtime::path(),
            'version'=>self::VERSION,
            'php'=>PHP_VERSION
        ));
    }

}
