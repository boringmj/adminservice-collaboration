<?php

namespace base;

use function array_key_exists;
use function in_array;
use function time;

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
        if(array_key_exists('expires',$options)&&$options['expires']!==null)
            $expires=(int)$options['expires'];
        elseif(array_key_exists('expire',$options)&&$options['expire']!==null)
            $expires=time()+(int)$options['expire'];
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

}
