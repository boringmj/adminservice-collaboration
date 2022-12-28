<?php

namespace app\index\model;

use base\Model;
use AdminService\File;
use AdminService\Exception;

class Count extends Model {

    /**
     * 文件对象
     */
    private File $file;

    /**
     * 构造方法
     * 
     * @access public
     * @param File $file 文件对象
     */
    public function __construct(File $file=(new File('count'))) {
        $this->file=$file;
    }

    /**
     * 添加计数器
     * 
     * @access public
     * @return int
     */
    public function add(): int {
        try {
            $file=$this->file;
            $count=$file->get('count',0);
            $file->set('count',$count+1,true);
            return $file->get('count',0);
        } catch(Exception) {
            return -1;
        }
    }

}

?>