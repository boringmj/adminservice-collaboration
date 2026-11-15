<?php

namespace AdminService\Worker;

use AdminService\Exception;

use function function_exists;
use function gzencode;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;

/**
 * 常驻 worker 响应压缩
 *
 * - 仅在客户端声明 `Accept-Encoding: gzip` 且正文达到最小长度时压缩
 * - 已带 `Content-Encoding` 或图片类型不压缩; 不改变调用方未要求压缩的语义
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class ResponseCompressor {

    /**
     * 最小压缩长度(字节)
     * @var int
     */
    private const MIN_LENGTH=1024;

    /**
     * 压缩级别
     * @var int
     */
    private int $level;

    /**
     * 构造方法
     *
     * @access public
     * @param int $level 压缩级别 1-9
     * @throws Exception zlib 不可用或级别无效
     */
    public function __construct(int $level=4) {
        if(!function_exists('gzencode'))
            throw new Exception('Response compression requires the zlib extension.');
        if($level<1||$level>9)
            throw new Exception('Invalid compression level: '.$level.' (expected 1..9).');
        $this->level=$level;
    }

    /**
     * 压缩正文并补充响应头
     *
     * @access public
     * @param string $body 原始正文
     * @param string $accept_encoding 请求的 `Accept-Encoding`
     * @param array<string,array<int,string>> $headers 响应头(压缩时写入 `Content-Encoding` 与 `Vary`)
     * @return string|null 压缩后的正文; 不满足压缩条件时为 null
     * @throws Exception gzip 压缩失败
     */
    public function compress(string $body,string $accept_encoding,array &$headers): ?string {
        if($body===''||strlen($body)<self::MIN_LENGTH)
            return null;
        if(stripos($accept_encoding,'gzip')===false)
            return null;
        if($this->header($headers,'content-encoding')!==null)
            return null;
        $type=strtolower($this->header($headers,'content-type')??'');
        if(str_starts_with($type,'image/'))
            return null;
        $compressed=gzencode($body,$this->level);
        if($compressed===false)
            throw new Exception('gzip compression failed.');
        $headers['content-encoding']=array('gzip');
        $vary=$headers['vary']??array();
        foreach($vary as $value) {
            if(stripos((string)$value,'accept-encoding')!==false)
                return $compressed;
        }
        $vary[]='Accept-Encoding';
        $headers['vary']=array_values($vary);
        return $compressed;
    }

    /**
     * 读取响应头(名称不区分大小写)
     *
     * @access private
     * @param array<string,array<int,string>> $headers 响应头
     * @param string $name 头名称
     * @return string|null 首个值; 不存在时为 null
     */
    private function header(array $headers,string $name): ?string {
        foreach($headers as $key=>$values) {
            if(strtolower($key)!==$name)
                continue;
            return (string)((array)$values)[0];
        }
        return null;
    }

}
