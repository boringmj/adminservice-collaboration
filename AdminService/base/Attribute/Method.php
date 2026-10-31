<?php

namespace base\Attribute;

use function strtoupper;

/**
 * 路由请求方法
 *
 * - 与历史字符串写法兼容: `'GET'` 与 `Method::GET` 等价
 * - `ANY` 的值为 `*`, 与 `Router::any()` 的既有语义一致
 */
enum Method: string {

    case GET='GET';
    case POST='POST';
    case PUT='PUT';
    case PATCH='PATCH';
    case DELETE='DELETE';
    case OPTIONS='OPTIONS';
    case ANY='*';

    /**
     * 归一化为注册表使用的大写字符串
     *
     * @access public
     * @param mixed $method 请求方法(枚举或字符串)
     * @return string
     */
    public static function normalize(mixed $method): string {
        if($method instanceof self)
            return $method->value;
        return strtoupper((string)$method);
    }

}
