<?php

namespace AdminService\View\Source;

use AdminService\Exception;
use base\View\TemplateSourceInterface;

use function file_get_contents;
use function is_file;
use function is_readable;
use function realpath;
use function sha1;

/**
 * 文件模板来源
 *
 * - 普通文件按需读取, 缓存命中时不需要读取模板内容
 *
 * @access public
 * @package AdminService\View\Source
 * @version 1.0.0
 */
final class FileSource implements TemplateSourceInterface {

    /**
     * 原始路径
     * @var string
     */
    private string $path;

    /**
     * 真实路径(非普通文件时为 null)
     * @var string|null
     */
    private ?string $real_path;

    /**
     * 已读取的内容
     * @var string|null
     */
    private ?string $content=null;

    /**
     * 构造方法
     *
     * @access public
     * @param string $path 模板路径
     * @throws Exception 文件不存在或不可读
     */
    public function __construct(string $path) {
        $this->path=$path;
        if(!is_file($path))
            throw new Exception('Template file not found.',100301,array('template_path'=>$path));
        $this->real_path=realpath($path)?:null;
        if(!is_readable($path))
            throw new Exception('Template file read failed.',100302,array('template_path'=>$path));
        // 非普通文件无法做稳定的缓存校验, 初始化时先确认可读
        if($this->real_path===null)
            $this->content();
    }

    /**
     * 模板内容
     *
     * @access public
     * @return string
     * @throws Exception 读取失败
     */
    public function content(): string {
        if($this->content!==null)
            return $this->content;
        $content=@file_get_contents($this->path);
        if($content===false)
            throw new Exception('Template file read failed.',100302,array('template_path'=>$this->path));
        return $this->content=$content;
    }

    /**
     * 参与缓存的文件路径
     *
     * @access public
     * @return string|null
     */
    public function path(): ?string {
        return $this->real_path;
    }

    /**
     * 源指纹
     *
     * - 按文件内容计算, 同秒同长度修改可见; 读取失败回落路径哈希
     *
     * @access public
     * @return string
     */
    public function fingerprint(): string {
        $content=@file_get_contents($this->path);
        if($content===false)
            return sha1($this->path);
        return sha1($content);
    }

}
