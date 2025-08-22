<?php

namespace AdminService;

use AdminService\App;
use AdminService\Config;
use AdminService\Exception;
use AdminService\UploadFile;
use base\AbstractUploadFiles;

final class UploadFiles extends AbstractUploadFiles {

    /**
     * 最大文件大小
     * @var int
     */
    protected int $max_size=0;

    /**
     * 文件名过滤规则
     * @var string
     */
    protected string $name_rule='';

    /**
     * 文件扩展名过滤规则
     * @var string
     */
    protected string $ext_rule='';

    /**
     * 默认hash算法
     * @var string
     */
    protected string $hash_algo='';

    /**
     * 构造方法
     * 
     * @access public
     * @param string $dir 上传目录
     * @param array $files 上传文件列表
     */
    public function __construct(string $dir,array $files) {
        // 检查目录是否存在,如果不存在则创建
        if(!is_dir($dir)) {
            $dir_mode=Config::get('request.default.upload.save.mode',0755);
            if(!mkdir($dir,$dir_mode,true)) {
                throw new Exception('创建上传目录失败');
            }
        }
        $this->max_size=Config::get('request.default.upload.max_size',104857600);
        $this->name_rule=Config::get('request.default.upload.name_rule','/[^a-zA-Z\.0-9_\-]/');
        $this->ext_rule=Config::get('request.default.upload.ext_rule','/[^0-9a-z-]/');
        $this->hash_algo=Config::get('request.default.upload.hash.algo','sha1');
        parent::__construct($dir,$files);
    }

    /**
     * 获取数组形式的上传文件列表
     * 
     * @access public
     * @return array
     */
    public function toArray(): array {
        return array_map(function($file) {
            return $file->toArray();
        },$this->files);
    }

    /**
     * 解析文件列表
     * 
     * @access protected
     * @param array $files 文件列表
     * @return void
     */
    protected function parse(array $files): void {
        $file_keys=['name','size','error','tmp_name'];
        // 标准化为多维数组
        if(!is_array($files['name']??[])) {
            $standardized=[];
            foreach($file_keys as $k)
                $standardized[$k]=[$files[$k]];
            $files=$standardized;
        }
        foreach(array_keys($files['name']??[]) as $i) {
            // 验证结构完整性
            foreach($file_keys as $key) {
                if(!isset($files[$key][$i])) {
                    continue 2;
                }
            }
            $file_size=$files['size'][$i];
            // 处理文件大小验证
            if($file_size>$this->max_size)
                throw new Exception('Uploaded file size exceeded');
            $error=$files['error'][$i];
            $tmp_file=$files['tmp_name'][$i];
            $file_name=$files['name'][$i]??'unknown';
            // 获取MIME类型
            $mime_type=$files['type'][$i]??'application/octet-stream';
            // 仅处理成功上传
            if($error!==UPLOAD_ERR_OK) {
                if($error!==UPLOAD_ERR_NO_FILE) {
                    // 忽略"未选择文件"错误
                    throw new Exception('Invalid file upload',0,[
                        'index'=>$i,
                        'error'=>$error,
                    ]);
                }
                continue;
            }
            // 安全验证
            if(!is_uploaded_file($tmp_file)||!file_exists($tmp_file))
                continue;
            // 安全处理扩展名
            $raw_ext=strtolower(pathinfo($file_name,PATHINFO_EXTENSION));
            $safe_ext=preg_replace(
                $this->ext_rule,
                '',
                $raw_ext
            );
            // 过滤文件名
            $file_name=preg_replace(
                $this->name_rule,
                '',
                $file_name
            );
            $this->addFile(App::new(
                UploadFile::class,
                name:$file_name,
                type:$mime_type,
                size:$file_size,
                extension:$safe_ext,
                path:$tmp_file,
                confirm_dir:$this->dir,
                hash_algo:$this->hash_algo
            ));

        }
    }

}