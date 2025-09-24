<?php

namespace AdminService;

use base\AbstractUploadFile;
use base\UploadStorageInterface;
use AdminService\exception\UploadException;
use AdminService\exception\UploadStorageException;

final class UploadFile extends AbstractUploadFile {

    /**
     * 最终保存路径
     * @var string $save_path
     */
    protected ?string $save_path=null;

    /**
     * 设置最终上传目录
     * @access public
     * @param string $dir 最终上传目录
     * @throws UploadException
     * @return void
     */
    public function setConfirmDir(string $dir): void {
        if(!is_dir($dir)||!is_writable($dir)) {
            throw new UploadException('上传目录不存在或不可写');
        }
        $this->confirm_dir=$dir;
    }

    /**
     * 计算文件哈希值
     * 
     * @access public
     * @param string|null $algo 哈希算法
     * @throws UploadException
     * @return string
     */
    public function calcHash(?string $algo=null): string {
        if($algo===null) $algo=$this->hash_algo;
        $file_path=$this->getTempPath();
        if(!file_exists($file_path)||!is_readable($file_path))
            throw new UploadException('文件不存在或不可读');
        if(!in_array($algo,hash_algos()))
            throw new UploadException('不支持的哈希算法');
        $hash=hash_file($algo,$file_path);
        if($hash===false) throw new UploadException('计算文件哈希值失败');
        return $hash;
    }

    /**
     * 保存文件
     * 
     * @access public
     * @param UploadStorageInterface $upload_storage 文件存储对象
     * @throws UploadStorageException
     * @return void
     */
    public function save(UploadStorageInterface $upload_storage): void {
        $upload_storage->save($this);
        $this->save_path=$upload_storage->getLastSavePath();
    }

    /**
     * 获取最终保存路径
     * 
     * @access public
     * @throws UploadException
     * @return string
     */
    public function getSavePath(): string {
        if($this->save_path!==null) return $this->save_path;
        throw new UploadException('文件未保存');
    }

    /**
     * 返回文件信息数组
     * 
     * @access public
     * @return array
     */
    public function toArray(): array {
        return [
            'name'=>$this->name,
            'extension'=>$this->extension,
            'size'=>$this->size,
            'type'=>$this->type,
            'hash'=>$this->hash,
            'confirm_name'=>$this->confirm_name,
            'save_path'=>$this->save_path
        ];
    }

}