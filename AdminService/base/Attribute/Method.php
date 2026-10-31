<?php

namespace base\Attribute;

use InvalidArgumentException;

use function is_string;
use function strtoupper;

/**
 * 路由请求方法
 *
 * - 与历史字符串写法兼容: `'GET'` 与 `Method::GET` 等价
 * - `ANY` 的值为 `*`, 与 `Router::any()` 的既有语义一致
 */
enum Method: string {

    /**
     * 匹配 GET 请求, 同时承接 HEAD
     */
    case GET='GET';

    /**
     * 匹配 POST 请求
     */
    case POST='POST';

    /**
     * 匹配 PUT 请求
     */
    case PUT='PUT';

    /**
     * 匹配 PATCH 请求
     */
    case PATCH='PATCH';

    /**
     * 匹配 DELETE 请求
     */
    case DELETE='DELETE';

    /**
     * 匹配 OPTIONS 请求
     */
    case OPTIONS='OPTIONS';

    /**
     * 匹配任意请求方法(通配 `*`)
     */
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
        if(!is_string($method)||$method==='')
            throw new InvalidArgumentException('Route method must be a non-empty string or Method enum.');
        return strtoupper($method);
    }

}
