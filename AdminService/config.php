<?php

namespace AdminService;

return array(
    // app 相关配置
    'app'=>array(
        'path'=>__DIR__.'/app',
    ),

    // route 相关配置
    'route'=>array(
        'default'=>array(
            'app'=>'Index',
            'controller'=>'Index',
            'method'=>'index'
        )
    )
);

?>