<?php

namespace AdminService;

$autoload_path=__DIR__."/../vendor/autoload.php";

# 加载composer自动加载文件
if(is_file($autoload_path))
    require_once $autoload_path;
else
    exit("Error: Unable to complete autoload!");

use AdminService\Main;

# 初始化服务
$Main=new Main();
$Main->init()->run();

?>