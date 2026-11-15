<?php

namespace AdminService\Worker;

use function strtoupper;

/**
 * 常驻 worker 请求工厂
 *
 * - 驱动把协议请求拆成参数后交给这里, 核心不依赖具体扩展
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class RequestFactory {

    /**
     * 构造请求
     *
     * @access public
     * @param string $method 请求方法
     * @param string $uri 请求 URI
     * @param array<string,mixed> $headers 请求头
     * @param array<string,mixed> $query 查询参数
     * @param array<string,mixed> $post 表单参数
     * @param array<string,mixed> $cookies Cookie
     * @param string $rawInput 原始请求体
     * @param array<string,mixed> $files 上传文件
     * @param array<string,mixed> $server Server 数据
     * @param string|null $queryString 原始查询串
     * @param bool $trusted_files 文件是否由驱动托管
     * @return WorkerRequest
     */
    public static function create(
        string $method,
        string $uri,
        array $headers=array(),
        array $query=array(),
        array $post=array(),
        array $cookies=array(),
        string $rawInput='',
        array $files=array(),
        array $server=array(),
        ?string $queryString=null,
        bool $trusted_files=false
    ): WorkerRequest {
        return new WorkerRequest(
            strtoupper($method),
            $uri,
            $headers,
            $query,
            $post,
            $cookies,
            $rawInput,
            $files,
            $server,
            $queryString,
            $trusted_files
        );
    }

}
