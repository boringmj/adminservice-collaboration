<?php

namespace AdminService\common;

use function chr;
use function dechex;
use function md5;
use function mt_rand;
use function str_pad;
use function strtolower;
use function strtoupper;
use function substr;
use function time;
use function uniqid;

/**
 * 生成UUID
 * 
 * @param bool $use_time 是否使用时间戳
 * @return string
 */
function uuid(bool $use_time=false): string {
    $char_id=strtoupper(md5(uniqid(mt_rand(),true)));
    $server_time=dechex(time());
    $server_time=str_pad(substr($server_time,-8),8,'0',STR_PAD_LEFT);
    $hyphen=chr(45);
    $uuid=($use_time?$server_time:substr($char_id,0,8)).$hyphen
        .substr($char_id,8,4).$hyphen
        .substr($char_id,12,4).$hyphen
        .substr($char_id,16,4).$hyphen
        .substr($char_id,20,12);
    // 转为全小写
    return strtolower($uuid);
}