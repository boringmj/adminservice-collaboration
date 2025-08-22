<?php

namespace AdminService;

use base\AbstractUploadFile;
use base\UploadStorageInterface;
use AdminService\exception\UploadStorageException;

final class UploadStorage implements UploadStorageInterface {

    /**
     * 最后一次最终保存路径
     * @var string $last_save_path
     */
    protected ?string $last_save_path=null;

    /**
     * 保存文件
     *
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function save(AbstractUploadFile $file): void {
        $this->validate($file);
        $save_path=$this->generateSavePath($file);
        if(!@move_uploaded_file($file->getTempPath(),$save_path))
            throw new UploadStorageException('保存文件失败');
        $real_path=realpath($save_path);
        $this->last_save_path=$real_path===false?$save_path:$real_path;
    }

    /**
     * 校验是否可以保存文件
     *
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function validate(AbstractUploadFile $file): void {
        // 检查存放目录是否可写
        $dir=$file->getConfirmDir();
        if(!is_dir($dir)||!is_writable($dir))
            throw new UploadStorageException('保存目录不可写或不为目录');
        // 检查文件是否存在
        $temp_path=$file->getTempPath();
        if(!file_exists($temp_path)||!is_readable($temp_path))
            throw new UploadStorageException('文件不存在或不可读');
    }

    /**
     * 获取文件保存地址
     *
     * @access public
     * @return string
     */
    public function getLastSavePath(): string {
        if($this->last_save_path!==null) return $this->last_save_path;
        throw new UploadStorageException('文件未保存');
    }

    /**
     * 生成最终保存路径
     *
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return string
     */
    protected function generateSavePath(AbstractUploadFile $file): string {
        $path=$file->getConfirmDir()
            .DIRECTORY_SEPARATOR
            .$file->getConfirmName();
        return $path;
    }

}