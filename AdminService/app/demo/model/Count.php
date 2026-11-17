<?php

namespace app\demo\model;

use AdminService\App;
use AdminService\File;
use AdminService\Exception;

class Count {

    /**
     * 文件对象
     */
    private File $file;

    /**
     * 构造方法
     *
     * @access public
     * @throws Exception
     */
    public function __construct() {
        $this->file=App::get(File::class,'count');
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