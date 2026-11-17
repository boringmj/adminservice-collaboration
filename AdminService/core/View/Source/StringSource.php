<?php

namespace AdminService\View\Source;

use base\View\TemplateSourceInterface;

use function sha1;

/**
 * 字符串模板来源
 *
 * - 不参与文件缓存
 *
 * @access public
 * @package AdminService\View\Source
 * @version 1.0.0
 */
final class StringSource implements TemplateSourceInterface {

    /**
     * 模板内容
     * @var string
     */
    private string $content;

    /**
     * 构造方法
     *
     * @access public
     * @param string $content 模板内容
     */
    public function __construct(string $content) {
        $this->content=$content;
    }

    /**
     * 模板内容
     *
     * @access public
     * @return string
     */
    public function content(): string {
        return $this->content;
    }

    /**
     * 参与缓存的文件路径
     *
     * @access public
     * @return null
     */
    public function path(): ?string {
        return null;
    }

    /**
     * 源指纹
     *
     * @access public
     * @return string
     */
    public function fingerprint(): string {
        return sha1($this->content);
    }

}
