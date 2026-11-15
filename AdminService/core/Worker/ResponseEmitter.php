<?php

namespace AdminService\Worker;

use AdminService\Response;
use base\Request;

/**
 * 常驻 worker 响应导出
 *
 * - 把请求级 `Response` 收敛成与协议无关的 `WorkerResponse`
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class ResponseEmitter {

    /**
     * 导出响应
     *
     * @access public
     * @param Response $response 请求级响应
     * @param Request $request 当前请求
     * @return WorkerResponse
     */
    public function emit(Response $response,Request $request): WorkerResponse {
        $data=$response->export($request);
        return new WorkerResponse(
            $data['status'],
            $data['headers'],
            $data['cookies'],
            $data['body'],
            $data['stream']??null
        );
    }

}
