<?php

namespace AdminService;

use Closure;

use function array_diff;
use function array_filter;
use function array_pop;
use function class_exists;
use function count;
use function class_implements;
use function get_declared_classes;
use function get_parent_class;
use function in_array;
use function interface_exists;

/**
 * 类查找器
 *
 * - 职责: 在"已声明的类"里按继承/实现关系查找**可实例化**的子类或实现类
 * - 依赖方向: 本类**不依赖容器**; "类名 → 真实类名(别名与绑定)"由容器在构造时回填回调
 *   (`setClassResolver()`), 于是 容器 → 类查找器 是单向的
 * - 只服务 `core/` 内部, 因此不抽契约
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class ClassFinder {

    /**
     * 反射缓存
     * @var ReflectionCache
     */
    private ReflectionCache $reflections;

    /**
     * 真实类名解析回调(签名: string $class => string)
     * @var Closure|null
     */
    private ?Closure $class_resolver=null;

    /**
     * 全量扫描次数(诊断 / 测试用)
     * @var int
     */
    private int $scans=0;

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
     * 设置真实类名解析回调
     *
     * @access public
     * @param callable $resolver 回调(接收类名, 返回解析别名与绑定后的类名)
     * @return void
     */
    public function setClassResolver(callable $resolver): void {
        $this->class_resolver=$resolver(...);
    }

    /**
     * 逐级寻找一个类的直接子类并查找可实例化的子类(支持别名和绑定)
     *
     * - 缓存口径(挂在 `ReflectionCache`, 故请求级容器与父容器共享同一份):
     *   **只缓存顶层查询里"找到了"的结果**。递归内部不缓存(结果依赖"防环标识");
     *   否定结果也不缓存(见 `ReflectionCache::$sub_classes` 的说明)
     * - ⚠ 已知限制: 只在**已声明类**里找子类, 因此某个子类的文件还没被加载时找不到它 ——
     *   结果受"进程里恰好加载了哪些类"影响。正因如此, 否定结果**不缓存**(免得把临时答案固化)。
     *
     * @access public
     * @param string $class 类名
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止解析死循环)
     * @return ?string
     */
    public function findDirectSubClassRecursive(string $class,array &$flags=array()): ?string {
        $top=$flags===array();
        // 缓存键用**解析后**的名字: 别名/绑定变了就是另一个键, 不会拿到旧映射的结果
        $resolved=$this->resolve($class);
        if($top) {
            $cached=$this->reflections->getSubClass($resolved);
            if($cached!==null)
                return $cached;
        }
        $found=$this->findSubClass($class,$flags);
        if($top&&$found!==null)
            $this->reflections->setSubClass($resolved,$found);
        return $found;
    }

    /**
     * 查找主体(不含缓存)
     *
     * @access private
     * @param string $class 类名
     * @param array<mixed> $flags 防环标识(引用传递)
     * @return ?string
     */
    private function findSubClass(string $class,array &$flags): ?string {
        // 获取真实类名
        $class=$this->resolve($class);
        // 如果标识重复则直接返回
        if(in_array($class,$flags))
            return null;
        // 判断类是否存在
        if(!class_exists($class)&&!interface_exists($class))
            return null;
        // 判断自身是否可实例化
        $ref=$this->reflections->getClass($class);
        if($ref->isInstantiable())
            return $class;
        // 获取所有已声明类
        $all_classes=get_declared_classes();
        $this->scans++;
        // 区分是类还是接口
        $is_class=class_exists($class);
        $is_interface=interface_exists($class);
        // 筛选直接子类或直接实现接口的类
        $direct_sub_classes=array_filter($all_classes,function($sub_class) use ($class,$is_class,$is_interface) {
            // 直接继承
            if($is_class&&get_parent_class($sub_class)===$class)
                return true;
            // 直接实现接口
            if($is_interface) {
                $all_interfaces=class_implements($sub_class,true);
                $parent=get_parent_class($sub_class);
                $parent_interfaces=$parent?class_implements($parent,true):[];
                $direct_interfaces=array_diff($all_interfaces,$parent_interfaces);
                if(in_array($class,$direct_interfaces,true))
                    return true;
            }
            return false;
        });
        // 遍历直接子类，找到可实例化的
        foreach($direct_sub_classes as $sub_class) {
            $sub_ref=$this->reflections->getClass($sub_class);
            if($sub_ref->isInstantiable())
                return $sub_class;
            // 递归查找子类的子类
            $flags[]=$class;
            $found=$this->findDirectSubClassRecursive($sub_class,$flags);
            // 移出标识中的目标类
            array_pop($flags);
            if($found!==null)
                return $found;
        }
        // 没有找到可实例化子类
        return null;
    }

    /**
     * 本实例做过几次"全量扫描"(诊断 / 测试用: 用来证明缓存之后不再重复扫)
     *
     * @access public
     * @return int
     */
    public function scanCount(): int {
        return $this->scans;
    }

    /**
     * 获取给出类型中的第一个可实例化的类(支持别名和绑定)
     *
     * @access public
     * @param array<string> $types 类型数组
     * @return ?string
     */
    public function getFirstInstantiableClass(array $types): ?string {
        foreach($types as $type) {
            // 判断是否可以实例化该类
            $class_name=$this->resolve($type);
            if(class_exists($class_name)) {
                // 通过反射判断是否可以实例化该类
                $ref_type=$this->reflections->getClass($class_name);
                if(!$ref_type->isInstantiable()) {
                    // 如果不可以实例化则尝试寻找一个可实例化的子类
                    $class_name=$this->findDirectSubClassRecursive($class_name);
                    if($class_name!==null)
                        return $class_name;
                    else
                        continue;
                }
                return $class_name;
            }
            // 处理接口
            if(interface_exists($class_name)) {
                // 尝试寻找可实例化的实现类
                $class_name=$this->findDirectSubClassRecursive($class_name);
                if($class_name!==null)
                    return $class_name;
                continue;
            }
        }
        return null;
    }

    /**
     * 解析真实类名(别名与绑定)
     *
     * @access private
     * @param string $class 类名或别名
     * @return string
     */
    private function resolve(string $class): string {
        if($this->class_resolver===null)
            return $class;
        return ($this->class_resolver)($class);
    }

}
