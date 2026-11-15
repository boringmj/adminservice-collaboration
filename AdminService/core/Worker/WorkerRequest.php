<?php

namespace AdminService\Worker;

use function array_merge;
use function http_build_query;
use function is_array;
use function parse_str;
use function parse_url;
use function strtoupper;

/**
 * 常驻 worker 请求
 *
 * - 与 SAPI 无关, 由驱动从协议请求构造
 * - `sources()` 输出 `HttpRequest` 可接受的输入源
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class WorkerRequest {

    /**
     * 构造方法
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
     * @param string|null $queryString 原始查询串(null = 从 URI/参数推导)
     */
    public function __construct(
        private string $method,
        private string $uri,
        private array $headers=array(),
        private array $query=array(),
        private array $post=array(),
        private array $cookies=array(),
        private string $rawInput='',
        private array $files=array(),
        private array $server=array(),
        private ?string $queryString=null
    ) {
    }

    /**
     * 请求方法
     *
     * @access public
     * @return string
     */
    public function method(): string {
        return strtoupper($this->method)===''?'GET':strtoupper($this->method);
    }

    /**
     * 请求 URI
     *
     * @access public
     * @return string
     */
    public function uri(): string {
        return $this->uri===''?'/':$this->uri;
    }

    /**
     * 输入源
     *
     * @access public
     * @return array{
     *     server:array<string,mixed>,
     *     headers:array<string,mixed>,
     *     query:array<string,mixed>,
     *     post:array<string,mixed>,
     *     cookie:array<string,mixed>,
     *     rawInput:string,
     *     files:array<string,mixed>
     * }
     */
    public function sources(): array {
        $uri=$this->uri();
        $method=$this->method();
        $url=parse_url($uri);
        $query_string=$this->queryString;
        if($query_string===null) {
            if($this->query!==array())
                $query_string=http_build_query($this->query);
            elseif(is_array($url)&&isset($url['query']))
                $query_string=(string)$url['query'];
            else
                $query_string='';
        }
        $query=$this->query;
        if($query===array()&&$query_string!=='')
            parse_str($query_string,$query);
        $server=array_merge($this->server,array(
            'REQUEST_URI'=>$uri,
            'REQUEST_METHOD'=>$method,
            'QUERY_STRING'=>$query_string
        ));
        if(!isset($server['SCRIPT_NAME']))
            $server['SCRIPT_NAME']='/index.php';
        if(!isset($server['PHP_SELF']))
            $server['PHP_SELF']='/index.php';
        return array(
            'server'=>$server,
            'headers'=>$this->headers,
            'query'=>$query,
            'post'=>$this->post,
            'cookie'=>$this->cookies,
            'rawInput'=>$this->rawInput,
            'files'=>$this->files
        );
    }

}
