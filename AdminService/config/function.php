<?php

namespace AdminService\config;

// function 相关配置
return array(
    'path'=>__DIR__.'/../common', // 公共函数目录
    'loader'=>array( // 需要自动加载的函数和类文件名(不含扩展名)
        'controller_helper', // 请求/控制器助手函数(view/json/param)
        'HttpHelper' // HttpHelper类(用于发送http请求)
    )
);