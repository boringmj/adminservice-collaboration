<?php

namespace AdminService\Worker;

use AdminService\Exception;
use AdminService\exception\ClientDisconnectedException;

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
     * @param bool $compression 是否启用 gzip 响应压缩
     * @param int $compression_level 压缩级别 1-9
     * @return void
     * @throws Exception Swoole 扩展不可用, 或压缩配置无效
     */
    public function serve(
        string $host,
        int $port,
        int $workers=1,
        bool $compression=false,
        int $compression_level=4
    ): void {
        if(!extension_loaded('swoole')||!class_exists('Swoole\\Http\\Server'))
            throw new Exception('Swoole extension is required for worker start.');
        $compressor=$compression?new ResponseCompressor($compression_level):null;
        $server=new \Swoole\Http\Server($host,$port);
        $server->set(array(
            'worker_num'=>max(1,$workers),
            'enable_coroutine'=>true,
            // 压缩统一由驱动处理, 避免 Swoole 内置压缩重复处理正文
            'http_compression'=>false
        ));
        $worker=$this->worker;
        $server->on('request',function(\Swoole\Http\Request $request,\Swoole\Http\Response $response) use($worker,$compressor): void {
            try {
                $server_data=RequestFactory::normalizeServer($request->server??array());
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
                // 写出在请求收尾前执行, 流式生成器可读取请求级状态
                $worker->handle($worker_request,function(WorkerResponse $worker_response) use($response,$request,$compressor): void {
                    $body=$worker_response->body();
                    $stream=$worker_response->stream();
                    $headers=$worker_response->headers();
                    if($compressor!==null&&$stream===null)
                        $body=$compressor->compress($body,(string)($request->header['accept-encoding']??''),$headers)??$body;
                    $response->status($worker_response->status());
                    foreach($headers as $name=>$values) {
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
                    if($stream!==null) {
                        // 分块写入; 生成器异常时只能截断响应, 由日志记录
                        try {
                            $stream(static function(string $chunk) use($response): void {
                                if($chunk==='')
                                    return;
                                if($response->write($chunk)===false)
                                    throw new ClientDisconnectedException('Client disconnected during stream response.');
                            });
                        } catch(ClientDisconnectedException) {
                            // 客户端已断开, 正文无法继续
                        } catch(\Throwable $e) {
                            error_log('Worker stream failed: '.$e->getMessage());
                        }
                        $response->end();
                        return;
                    }
                    $response->end($body);
                });
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
