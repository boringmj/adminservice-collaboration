<?php

namespace AdminService\Worker;

use AdminService\Exception;

use function class_exists;
use function error_log;
use function extension_loaded;
use function implode;
use function is_array;
use function max;
use function strtolower;

/**
 * Swoole HTTP 常驻驱动
 *
 * - 只负责协议适配; 请求作用域由 `Worker` 负责
 * - 需要 Swoole 扩展, 未安装时明确抛错
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class SwooleDriver {

    /**
     * Worker
     * @var Worker
     */
    private Worker $worker;

    /**
     * 构造方法
     *
     * @access public
     * @param Worker $worker Worker
     */
    public function __construct(Worker $worker) {
        $this->worker=$worker;
    }

    /**
     * 启动服务
     *
     * @access public
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param int $workers 进程数
     * @return void
     * @throws Exception Swoole 扩展不可用
     */
    public function serve(string $host,int $port,int $workers=1): void {
        if(!extension_loaded('swoole')||!class_exists('Swoole\\Http\\Server'))
            throw new Exception('Swoole extension is required for worker start.');
        /** @var \Swoole\Http\Server $server */
        $server=new \Swoole\Http\Server($host,$port);
        $server->set(array(
            'worker_num'=>max(1,$workers),
            'enable_coroutine'=>true
        ));
        $worker=$this->worker;
        $server->on('request',function(\Swoole\Http\Request $request,\Swoole\Http\Response $response) use($worker): void {
            try {
                $server_data=$request->server??array();
                $worker_request=RequestFactory::create(
                    (string)($server_data['request_method']??'GET'),
                    (string)($server_data['request_uri']??'/'),
                    $request->header??array(),
                    $request->get??array(),
                    $request->post??array(),
                    $request->cookie??array(),
                    (string)($request->rawContent()??''),
                    $request->files??array(),
                    $server_data,
                    isset($server_data['query_string'])?(string)$server_data['query_string']:null,
                    true
                );
                $worker_response=$worker->handle($worker_request);
                $response->status($worker_response->status());
                foreach($worker_response->headers() as $name=>$values) {
                    $lower=strtolower((string)$name);
                    if($lower==='content-length'||$lower==='connection'||$lower==='set-cookie')
                        continue;
                    $response->header((string)$name,implode(', ',(array)$values));
                }
                foreach($worker_response->cookies() as $name=>$item) {
                    if(is_array($item)) {
                        $response->cookie(
                            (string)$name,
                            (string)($item['value']??''),
                            (int)($item['expire']??0),
                            (string)($item['path']??'/'),
                            (string)($item['domain']??''),
                            (bool)($item['secure']??false),
                            (bool)($item['httponly']??false)
                        );
                    } else
                        $response->cookie((string)$name,(string)$item);
                }
                $response->end($worker_response->body());
            } catch(\Throwable $e) {
                error_log('Worker request failed: '.$e->getMessage());
                $response->status(500);
                $response->header('Content-Type','text/plain; charset=utf-8');
                $response->end('Internal Server Error');
            }
        });
        $server->start();
    }

}
