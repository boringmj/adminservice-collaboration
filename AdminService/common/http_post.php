<?php

namespace AdminService\common;


/**
 * 发送HTTP POST请求
 * 
 * @param string $url 请求地址
 * @param array $params 请求参数
 * @param string $type 请求类型(form/json)
 * @return string
 */
function httpPost(string $url,array $params=array(),string $type='form'): string {
    $header='';
    if($type=='json') {
        $postdata=json_encode($params);
        $header='Content-type:application/json';
    } else {
        $postdata=http_build_query($params);
        $header='Content-type:application/x-www-form-urlencoded';
    }
    $options=array(
        'http'=>array(
            'method'=>'POST',
            'header'=>$header,
            'content'=>$postdata,
            'timeout'=>15 * 60 // 超时时间（单位:s）
        ),
        'ssl'=>array(
            'verify_peer'=>false,
            'verify_peer_name'=>false,
        )
    );
    $context=stream_context_create($options);
    $result=file_get_contents($url,false,$context);
    return $result;
}

?>