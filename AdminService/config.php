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
        ),
        'params'=>array(
            'toget'=>array(
                'model'=>'auto' // value, list, value-list, list-value (default: list-value)
            ),
            'rule'=>array(
                'app'=>'/^[A-Z_][a-zA-Z0-9_\-]+$/',
                'controller'=>'/^[A-Z_][a-zA-Z0-9_\-]+$/',
                'method'=>'/^[a-z_][a-zA-Z0-9_\-]+$/',
                'get'=>'/^[a-zA-Z0-9_]+$/'
            )
        )
    )
);

?>