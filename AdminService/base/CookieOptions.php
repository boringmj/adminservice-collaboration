<?php

namespace base;

use function array_key_exists;
use function get_debug_type;
use function in_array;
use function is_int;
use function is_scalar;
use function time;
use function var_export;

/**
 * Cookie 属性
 *
 * - `expires` 为绝对 Unix 时间戳; `0` 表示会话 Cookie; `null` 使用配置默认值
 * - `null` 属性表示使用 `cookie.*` 配置默认值
 */
final class CookieOptions {

    /**
     * 构造方法
     *
     * @access public
     * @param string $value 值
     * @param int|null $expires 绝对过期时间戳
     * @param string|null $path 路径
     * @param string|null $domain 域名
     * @param bool|null $secure 是否仅 HTTPS
     * @param bool|null $httponly 是否禁止脚本访问
     * @param string|null $samesite 跨站发送策略
     */
    public function __construct(
        public readonly string $value='',
        public readonly ?int $expires=null,
        public readonly ?string $path=null,
        public readonly ?string $domain=null,
        public readonly ?bool $secure=null,
        public readonly ?bool $httponly=null,
        public readonly ?string $samesite=null
    ) {
    }

    /**
     * 从数组构建
     *
     * - 兼容旧数组键 `expire`(相对秒数)与 `value`
     * - `expires`/`expire` 只接受整数与整数字符串, 其他值抛异常
     *
     * @access public
     * @param array<string,mixed> $options 选项
     * @return self
     */
    public static function fromArray(array $options): self {
        $allowed=array('name','value','expires','expire','path','domain','secure','httponly','samesite');
        foreach($options as $key=>$value)
            if(!in_array($key,$allowed,true))
                throw new \InvalidArgumentException('Cookie option "'.$key.'" is not supported.');
        if(array_key_exists('expires',$options)&&array_key_exists('expire',$options))
            throw new \InvalidArgumentException('Cookie options cannot contain both "expire" and "expires".');
        $expires=null;
        if(array_key_exists('expires',$options)&&$options['expires']!==null) {
            self::assertIntegerOption($options['expires'],'expires');
            $expires=(int)$options['expires'];
        }
        elseif(array_key_exists('expire',$options)&&$options['expire']!==null) {
            self::assertIntegerOption($options['expire'],'expire');
            $expires=time()+(int)$options['expire'];
        }
        return new self(
            (string)($options['value']??''),
            $expires,
            isset($options['path'])?(string)$options['path']:null,
            isset($options['domain'])?(string)$options['domain']:null,
            isset($options['secure'])?(bool)$options['secure']:null,
            isset($options['httponly'])?(bool)$options['httponly']:null,
            isset($options['samesite'])?(string)$options['samesite']:null
        );
    }

    /**
     * 校验时间选项只接受整数与整数字符串
     *
     * - 负数时间戳合法(用于过期删除); 空白、浮点与其他类型非法
     *
     * @access private
     * @param mixed $value 选项值
     * @param string $key 选项键名
     * @return void
     */
    private static function assertIntegerOption(mixed $value,string $key): void {
        if(is_int($value))
            return;
        if(is_string($value)&&$value===(string)(int)$value)
            return;
        throw new \InvalidArgumentException('Cookie option "'.$key.'" must be an integer or integer-like string, '.(is_scalar($value)?var_export($value,true):get_debug_type($value)).' given.');
    }

}
