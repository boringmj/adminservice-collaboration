<?php

namespace base;

use \Countable;
use \ArrayIterator;
use \IteratorAggregate;

/**
 * 上传文件列表类
 */
abstract class AbstractUploadFiles implements IteratorAggregate,Countable {
    
    /**
     * 上传目录
     * @var string
     */
    protected string $dir='';

    /**
     * 上传文件实例
     * @var AbstractUploadFile[]
     */
    protected array $files=[];

    /**
     * 构造方法
     * 
     * @access public
     * @param string $dir 上传目录
     * @param array $files 上传文件列表
     * @return void
     */
    public function __construct(string $dir, array $files) {
        $this->dir=$dir;
        $this->parse($files);
    }

    /**
     * 添加文件实例
     * 
     * @access public
     * @param AbstractUploadFile $file 文件实例
     * @return self
     */
    public function addFile(AbstractUploadFile $file): self {
        $this->files[]=$file;
        return $this;
    }

    /**
     * 批量添加文件实例
     * 
     * @access public
     * @param AbstractUploadFile[] $files 文件实例列表
     * @return self
     */
    public function addFiles(array $files): self {
        foreach($files as $file) {
            $this->addFile($file);
        }
        return $this;
    }

    /**
     * 获取上传目录
     * 
     * @access public
     * @return string
     */
    public function getDir(): string {
        return $this->dir;
    }

    /**
     * 设置上传目录
     * 
     * @access public
     * @param string $dir 上传目录
     * @return self
     */
    public function setDir(string $dir): self {
        $this->dir=$dir;
        return $this;
    }

    /**
     * 获取上传文件列表
     * 
     * @access public
     * @return AbstractUploadFile[]
     */
    public function getFiles(): array {
        return $this->files;
    }

    /**
     * 获取迭代器
     * 
     * @access public
     * @return ArrayIterator<int, AbstractUploadFile>
     */
    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->getFiles());
    }

    /**
     * 获取上传文件数量
     * 
     * @access public
     * @return int
     */
    public function count(): int {
        return count($this->getFiles());
    }
    
    /**
     * 获取数组形式的上传文件列表
     * 
     * @access public
     * @return array
     */
    abstract public function toArray(): array;

    /**
     * 解析文件列表
     * 
     * @access protected
     * @param array $files 文件数组
     * @return void
     */
    abstract protected function parse(array $files): void;

}