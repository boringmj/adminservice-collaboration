<?php

namespace bash;

use AdminService\Config;
use bash\Exception;

abstract class Data {
    
    /**
     * 以何种形式存储数据
     */
    public string $store_type;

    /**
     * 数据
     */
    public array $data;

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