<?php

namespace AdminService\common;

/**
 * 生成UUID
 * 
 * @param bool $use_time 是否使用时间戳
 * @return string
 */
function uuid(bool $use_time=false): string {
    $charid=strtoupper(md5(uniqid(mt_rand(),true)));
    $server_time=dechex(time());
    $server_time=str_pad(substr($server_time,-8),8,'0',STR_PAD_LEFT);
    $hyphen=chr(45);
    $uuid=($use_time?$server_time:substr($charid,0,8)).$hyphen
        .substr($charid, 8, 4).$hyphen
        .substr($charid,12, 4).$hyphen
        .substr($charid,16, 4).$hyphen
        .substr($charid,20,12);
    // 转为全小写
    $uuid=strtolower($uuid);
    return $uuid;
}

?>