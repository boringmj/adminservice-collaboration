<?php

namespace AdminService\Worker;

use function explode;
use function is_scalar;
use function strcasecmp;
use function strtoupper;
use function strtolower;
use function trim;

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
     * 补齐 Swoole server 数组中的 $_SERVER 风格大写键
     *
     * - 只补缺失项, 保留小写原键
     * - `HTTPS` 判定: 已有 `HTTPS` 键时保留; 否则 `SERVER_PORT` 为 443 或请求头
     *   `X-Forwarded-Proto` 声明 `https` 时视为 https
     *
     * @access public
     * @param array<string,mixed> $server Swoole server 数组
     * @param array<string,mixed> $headers 请求头(键大小写不限, 用于 HTTPS 判定)
     * @return array<string,mixed>
     */
    public static function normalizeServer(array $server,array $headers=array()): array {
        $keys=array(
            'remote_addr'=>'REMOTE_ADDR',
            'remote_port'=>'REMOTE_PORT',
            'server_port'=>'SERVER_PORT',
            'server_protocol'=>'SERVER_PROTOCOL',
            'server_software'=>'SERVER_SOFTWARE',
            'request_time'=>'REQUEST_TIME',
            'request_time_float'=>'REQUEST_TIME_FLOAT',
            'path_info'=>'PATH_INFO'
        );
        foreach($keys as $from=>$to)
            if(isset($server[$from])&&!isset($server[$to]))
                $server[$to]=$server[$from];
        if(!isset($server['HTTPS'])
            &&((string)($server['SERVER_PORT']??'')==='443'||self::forwardedProtoIsHttps($headers)))
            $server['HTTPS']='on';
        if(!isset($server['REQUEST_SCHEME']))
            $server['REQUEST_SCHEME']=($server['HTTPS']??'')==='on'?'https':'http';
        return $server;
    }

    /**
     * 判断代理转发头声明的协议是否为 https
     *
     * - TLS 终结代理回源时 `SERVER_PORT` 不是 443, 以该头为准
     * - 多级代理的逗号列表取第一段
     *
     * @access private
     * @param array<string,mixed> $headers 请求头(键大小写不限)
     * @return bool
     */
    private static function forwardedProtoIsHttps(array $headers): bool {
        foreach($headers as $name=>$value) {
            if(!is_scalar($value)||strcasecmp((string)$name,'x-forwarded-proto')!==0)
                continue;
            return strtolower(trim((string)explode(',',(string)$value)[0]))==='https';
        }
        return false;
    }

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
