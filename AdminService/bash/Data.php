<?php

namespace bash;

use AdminService\Config;
use AdminService\Exception;

abstract class Data {
    
    /**
     * 以何种形式存储数据
     */
    public string $store_type;

    /**
     * 数据
     */
    private array $data;

    /**
     * 数据存储名(默认为类名,如果是文件则为文件名,如果是数据库则为表名)
     */
    public string $store_name;

    /**
     * 构造方法
     * 
     * @access public
     */
    final public function __construct() {
        $this->store_type='file';
        $this->data=array();
    }
    
}

?>