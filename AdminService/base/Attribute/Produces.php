<?php

namespace base\Attribute;

use Attribute;
use InvalidArgumentException;

use function get_debug_type;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function str_contains;
use function strtolower;
use function trim;
use function var_export;

/**
 * 可生产内容类型声明(方法级)
 *
 * - 声明后由路由在处理器执行前完成内容协商, 不可接受时直接返回 406
 * - 未声明的方法沿用响应出口的全局协商
 * - 第一个类型是没有 `Accept` 或 `fallback` 时的默认类型
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Produces {

    /**
     * 可生产的内容类型(按声明顺序)
     * @var array<string>
     */
    private array $types;

    /**
     * 构造方法
     *
     * @access public
     * @param string|array<string> $types 内容类型(单个或数组)
     * @throws InvalidArgumentException 类型为空、不是字符串或格式非法
     */
    public function __construct(string|array $types) {
        $this->types=self::normalize($types);
    }

    /**
     * 归一内容类型列表
     *
     * - 去空白并转小写, 去重后保留首次出现顺序
     * - 必须是 `主类型/子类型` 形式的具体类型, 不含参数与通配符
     *
     * @access public
     * @param string|array<string> $types 内容类型(单个或数组)
     * @return array<string>
     * @throws InvalidArgumentException 类型为空、不是字符串或格式非法
     */
    public static function normalize(string|array $types): array {
        $list=is_array($types)?$types:array($types);
        $normalized=array();
        foreach($list as $type) {
            if(!is_string($type))
                throw new InvalidArgumentException('Produces type must be a string, got '.get_debug_type($type).'.');
            $type=strtolower(trim($type));
            if($type===''||preg_match('/^[^\/\s;]+\/[^\/\s;]+$/',$type)!==1||str_contains($type,'*'))
                throw new InvalidArgumentException('Invalid produces type: '.var_export($type,true).'; expected a concrete media type such as application/json.');
            if(!in_array($type,$normalized,true))
                $normalized[]=$type;
        }
        if($normalized===array())
            throw new InvalidArgumentException('Produces requires at least one media type.');
        return $normalized;
    }

    /**
     * 获取内容类型列表
     *
     * @access public
     * @return array<string>
     */
    public function getTypes(): array {
        return $this->types;
    }

}
