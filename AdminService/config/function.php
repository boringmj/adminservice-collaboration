<?php

namespace AdminService\config;

// function 相关配置
return array(
    'path'=>__DIR__.'/../common', // 公共函数目录
    'loader'=>array( // 需要自动加载的函数和类文件名(不含扩展名)
        'uuid', // uuid函数
        'helper', // 通用助手函数
        'controller_helper', // 控制器助手函数
        'sign', // 签名函数
        'http_post', // http_post函数
        'HttpHelper', // HttpHelper类(用于发送http请求)
    )
);

?>