<?php

namespace AdminService;

use ReflectionParameter;
use ReflectionProperty;
use ReflectionMethod;
use ReflectionException;
use Closure;
use base\ArgumentResolverInterface;
use base\Attribute\Config;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function gettype;
use function in_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function str_replace;

/**
 * 参数解析器
 *
 * - 职责: 参数合并(按名 / 按位 / 可变参数)、类型校验与静默转换、类型的标准化
 * - 依赖方向: 本类**不依赖容器**; "按类型取实例"由容器在构造时回填一个解析回调
 *   (`setInstanceResolver()`), 于是 容器 → 参数解析器 是单向的
 * - 状态(参数转换开关)是实例状态
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class ArgumentResolver implements ArgumentResolverInterface {

    /**
     * 反射缓存
     * @var ReflectionCache
     */
    private ReflectionCache $reflections;

    /**
     * 实例解析回调(签名: array<string> $types => ?object)
     * @var Closure|null
     */
    private ?Closure $instance_resolver=null;

    /**
     * 配置取值回调(签名: string $key, mixed $default => mixed)
     * @var Closure|null
     */
    private ?Closure $value_resolver=null;

    /**
     * 是否允许标量参数静默转换(对齐 PHP 弱类型)
     * @var bool
     */
    private bool $enable_param_cast=true;

    /**
     * 构造方法
     *
     * @access public
     * @param ReflectionCache|null $reflections 反射缓存(默认新建)
     */
    public function __construct(?ReflectionCache $reflections=null) {
        $this->reflections=$reflections??new ReflectionCache();
    }

    /**
     * 设置实例解析回调
     *
     * @access public
     * @param callable $resolver 回调(接收类型数组, 返回可实例化对象或 null)
     * @return void
     */
    public function setInstanceResolver(callable $resolver): void {
        $this->instance_resolver=$resolver(...);
    }

    /**
     * 设置是否允许标量参数静默转换
     *
     * @access public
     * @param bool $enable 是否允许
     * @return void
     */
    public function setParamCast(bool $enable): void {
        $this->enable_param_cast=$enable;
    }

    /**
     * 获取是否允许标量参数静默转换
     *
     * @access public
     * @return bool
     */
    public function getParamCast(): bool {
        return $this->enable_param_cast;
    }

    /**
     * 整理和合并参数
     *
     * - 合并顺序: 具名实参 → 顺位实参 → `#[Config]` 配置项(仅 `$allow_config` 为真)→ 按类型注入 → 形参默认值 → null
     * - `$allow_config` 仅"框架构建对象"的场景为真(容器构造对象、生命周期方法调用);
     *   控制器方法的形参调用保持为假, 因此**控制器形参只注入路由参数**
     *
     * @access public
     * @param ReflectionParameter[] $params 参数
     * @param array<mixed> $args 参数
     * @param bool $allow_config 是否允许 `#[Config]` 配置项注入
     * @return array
     * @throws Exception
     * @throws ReflectionException
     */
    public function merge(array $params,array $args,bool $allow_config=false): array {
        $params_temp=array();
        $arg_count=0;
        foreach($params as $param) {
            $type=$param->getType();
            $type=(string)$type;
            // 将类型分割为数组
            $types=explode('|',$type);
            $types=$this->getStandardTypes($types);
            // 获取参数名
            $name=$param->getName();
            if($param->allowsNull())
                $types[]='NULL';
            $types=array_unique($types);
            // 判断参数类型是否为可变参数
            if($param->isVariadic()) {
                $numeric_args=array_values(array_filter($args,'is_numeric',ARRAY_FILTER_USE_KEY));
                $assoc_args=array_filter($args,'is_string',ARRAY_FILTER_USE_KEY);
                $temp_args=array_merge($numeric_args,$assoc_args);
                // 判断是否有类型限制
                if(count($types)===1&&$types[0]==='') {
                    $params_temp=array_merge($params_temp,$temp_args);
                    break;
                }
                // 判断每个参数是否符合类型限制
                foreach($temp_args as $key=>$value) {
                    if($this->isValidType($value,$types)) {
                        // 对齐普通参数,通过校验后执行静默转换
                        $value=$this->castParam($value,$types);
                        if(is_numeric($key))
                            $params_temp[]=$value;
                        else
                            $params_temp[$key]=$value;
                        unset($args[$key]);
                    } else {
                        throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" is not valid.',0,array(
                            'class'=>$param,
                            'parameter'=>$param->getName(),
                            'error'=>'The parameter type is not valid.'
                        ));
                    }
                }
                break;
            }
            // 先尝试在参数数组通过参数名查找
            if(array_key_exists($name,$args)&&$this->isValidType($args[$name],$types)) {
                $params_temp[]=$this->castParam($args[$name],$types);
                unset($args[$name]);
                continue;
            }
            // 判断是否存在顺位参数
            if(array_key_exists($arg_count,$args)&&$this->isValidType($args[$arg_count],$types)) {
                $params_temp[]=$this->castParam($args[$arg_count],$types);
                unset($args[$arg_count]);
                // 顺位参数自增
                $arg_count++;
                continue;
            }
            // 配置项注入(仅构造 / 生命周期等"框架构建"场景; 控制器方法形参不注入配置)
            if($allow_config) {
                $config=$this->configAttribute($param);
                if($config!==null) {
                    $params_temp[]=$this->configValue($config,$types,$param->isDefaultValueAvailable()?$param->getDefaultValue():null);
                    continue;
                }
            }
            // 获取第一个可实例化的类
            $real_class=$this->resolveInstance($types);
            if($real_class!==null) {
                $params_temp[]=$real_class;
                continue;
            }
            elseif($param->isDefaultValueAvailable())
                $params_temp[]=$param->getDefaultValue();
            else if($param->allowsNull())
                $params_temp[]=null;
            else
                throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" is not valid.',0,array(
                    'class'=>$param,
                    'parameter'=>$param->getName(),
                    'error'=>'The parameter type is not valid or the parameter value is not set.'
                ));
        }
        return $params_temp;
    }

    /**
     * 判断参数是否符合预期类型
     *
     * @access public
     * @param mixed $arg 参数
     * @param array<string> $types 预期类型
     * @return bool
     */
    public function isValidType(mixed $arg,array $types): bool {
        $arg_type=gettype($arg);
        // 直接匹配 PHP 内置类型
        if(in_array($arg_type,$types,true)) return true;
        // 类型为空字符串(无类型约束)
        if($types===['']||in_array('',$types,true)) return true;
        // mixed 表示任何类型都合法
        if(in_array('mixed',$types,true)) return true;
        // 处理可执行类型
        if(in_array('callable',$types,true)) {
            if(is_callable($arg)) return true;
        }
        // 标量弱类型兼容(对齐 PHP 非严格模式,反射调用默认是严格类型)
        // 注意: $types 为 getStandardTypes() 标准化后的 gettype() 风格(integer/double/boolean)
        if($this->enable_param_cast) {
            // 数字字符串 → int/float
            if((in_array('integer',$types,true)||in_array('double',$types,true))&&is_numeric($arg))
                return true;
            // int/float/bool → string
            if(in_array('string',$types,true)&&(is_int($arg)||is_float($arg)||is_bool($arg)))
                return true;
            // float/bool → int
            if(in_array('integer',$types,true)&&(is_float($arg)||is_bool($arg)))
                return true;
            // int/bool → float
            if(in_array('double',$types,true)&&(is_int($arg)||is_bool($arg)))
                return true;
            // int/float/string → bool
            if(in_array('boolean',$types,true)&&(is_int($arg)||is_float($arg)||is_string($arg)))
                return true;
        }
        // 如果参数是对象，检查是否符合给定类名
        if($arg_type==='object') {
            foreach($types as $t) {
                if(class_exists($t) && $arg instanceof $t) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 将参数转换为目标类型(对齐 PHP 非严格模式的标量转换规则)
     *
     * 反射调用为严格类型,当实参类型与目标标量类型不一致时需要在这里显式转换,
     * 否则 invokeArgs 会抛出 TypeError。转换规则与 PHP 直接调用的弱类型行为一致:
     * - 数字字符串 → int/float
     * - int/float/bool → string
     * - float/bool → int, int/bool → float
     * - int/float/string → bool
     *
     * 注意: 转换规则必须与 isValidType() 的标量宽松规则保持一一对应,
     * 否则会出现"验证通过但未转换"导致 TypeError 的遗漏
     *
     * @access public
     * @param mixed $value 实参值
     * @param array<string> $types 目标类型列表
     * @return mixed
     */
    public function castParam(mixed $value,array $types): mixed {
        // 关闭静默转换时原样返回
        if(!$this->enable_param_cast)
            return $value;
        // $types 为 getStandardTypes() 标准化后的 gettype() 风格(integer/double/boolean)
        if(is_string($value)) {
            if(in_array('integer',$types,true)&&is_numeric($value))
                return (int)$value;
            if(in_array('double',$types,true)&&is_numeric($value))
                return (float)$value;
            if(in_array('boolean',$types,true))
                return (bool)$value;
        }
        elseif(is_int($value)) {
            if(in_array('string',$types,true))
                return (string)$value;
            if(in_array('double',$types,true))
                return (float)$value;
            if(in_array('boolean',$types,true))
                return (bool)$value;
        }
        elseif(is_float($value)) {
            if(in_array('string',$types,true))
                return (string)$value;
            if(in_array('integer',$types,true))
                return (int)$value;
            if(in_array('boolean',$types,true))
                return (bool)$value;
        }
        elseif(is_bool($value)) {
            if(in_array('string',$types,true))
                return $value?'1':'';
            if(in_array('integer',$types,true))
                return (int)$value;
            if(in_array('double',$types,true))
                return (float)$value;
        }
        return $value;
    }

    /**
     * 将一个PHP类型转为gettype()返回的类型
     *
     * @access public
     * @param string $type 类型
     * @return string
     */
    public function getStandardType(string $type): string {
        // 清除类型前缀
        $type=str_replace('?','',$type);
        $list=array(
            'int'=>'integer',
            'bool'=>'boolean',
            'float'=>'double',
            'null'=>'NULL'
        );
        if(isset($list[$type]))
            return $list[$type];
        return $type;
    }

    /**
     * 将一组PHP类型转为gettype()返回的类型
     *
     * @access public
     * @param array<string> $types 类型数组
     * @return array
     */
    public function getStandardTypes(array $types): array {
        $result=array();
        foreach($types as $type)
            $result[]=$this->getStandardType($type);
        return $result;
    }

    /**
     * 调用对象的方法(参数按签名合并)
     *
     * @access public
     * @param object $object 对象
     * @param string $method 方法名
     * @param array<mixed> $args 参数
     * @return mixed
     * @throws Exception
     * @throws ReflectionException
     */
    public function call(object $object,string $method,array $args=array()): mixed {
        $ref=$this->reflections->getMethodByObject($object,$method);
        $params=$ref->getParameters();
        // 框架在解析实参 → 显式标注的 #[Config] 一并生效(实参优先级更高)
        return $ref->invokeArgs($object,$this->merge($params,$args,true));
    }

    /**
     * 调用函数(参数按签名合并)
     *
     * @access public
     * @param string|callable $function 函数名或闭包
     * @param array<mixed> $args 参数
     * @return mixed
     * @throws Exception
     * @throws ReflectionException
     */
    public function callFunction(string|callable $function,array $args=array()): mixed {
        // 非字符串、非闭包的 callable(可调用对象 / array(对象, 方法))统一转成闭包;
        // 此前直接交给 ReflectionFunction 会因类型不符抛 TypeError, 而文档一直写着"支持闭包"
        if(!is_string($function)&&!$function instanceof Closure)
            $function=Closure::fromCallable($function);
        $ref=$this->reflections->getFunction($function);
        $params=$ref->getParameters();
        // 同 call(): 框架解析实参时, 显式标注的 #[Config] 生效
        return $ref->invokeArgs($this->merge($params,$args,true));
    }

    /**
     * 取目标上的 `#[Config]` 注解
     *
     * - 支持三种 target: 属性 / Setter 方法 / 形参;无注解时返回 null
     *
     * @access public
     * @param ReflectionProperty|ReflectionParameter|ReflectionMethod $target 属性、形参或方法
     * @return Config|null
     */
    public function configAttribute(ReflectionProperty|ReflectionParameter|ReflectionMethod $target): ?Config {
        $attributes=$target->getAttributes(Config::class);
        if($attributes===array())
            return null;
        return $attributes[0]->newInstance();
    }

    /**
     * 解析 `#[Config]` 的值(取配置 + 按目标类型做标量转换)
     *
     * - 配置缺失且注解未给默认值: 有**形参默认值**时用形参默认值(由 `$fallback` 传入), 否则为 null
     * - `$types` 为 gettype() 风格的类型列表(与 `castParam()` 对齐)
     *
     * @access public
     * @param Config $config 注解实例
     * @param array<string> $types 目标类型列表(gettype() 风格)
     * @param mixed $fallback 兜底值(通常是形参默认值)
     * @return mixed
     */
    public function configValue(Config $config,array $types=array(),mixed $fallback=null): mixed {
        $default=$config->hasDefault()?$config->getDefault():$fallback;
        // 未回填取值回调时退化为默认值(组件仍可单独使用), 转换规则一致
        $value=$this->value_resolver===null?$default:($this->value_resolver)($config->getKey(),$default);
        return $this->castParam($value,$types);
    }

    /**
     * 设置配置取值回调
     *
     * - 由容器回填(接到框架配置), 参数解析器因此不直接依赖配置实现
     *
     * @access public
     * @param callable $resolver 回调(接收配置键与默认值, 返回配置值)
     * @return void
     */
    public function setValueResolver(callable $resolver): void {
        $this->value_resolver=$resolver(...);
    }

    /**
     * 按类型解析出一个实例(由容器回填的回调完成, 未回填时返回 null)
     *
     * @access private
     * @param array<string> $types 类型数组
     * @return object|null
     */
    private function resolveInstance(array $types): ?object {
        if($this->instance_resolver===null)
            return null;
        return ($this->instance_resolver)($types);
    }

}
