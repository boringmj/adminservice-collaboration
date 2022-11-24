<?php

namespace AdminService\common;

/**
 * 签名(先按键名排序, 逐一按 "key=value" 用 "&" 拼接, 最后进行MD5)
 * 
 * @param array $data 需要签名的数据
 * @return string
 */
function sign($data)
{
    krsort($data);
    $sign_string='';
    foreach($data as $key=>$value)
        $sign_string.=(empty($sign_string)?'':'&')."{$key}={$value}";
    return md5($sign_string);
}

?>