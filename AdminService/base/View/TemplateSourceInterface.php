<?php

namespace base\View;

/**
 * 模板来源契约
 *
 * - 文件模板与字符串模板由不同实现提供
 * - `path()` 为空表示不参与文件缓存
 *
 * @access public
 * @package base\View
 * @version 1.0.0
 */
interface TemplateSourceInterface {

    /**
     * 模板内容
     *
     * @access public
     * @return string
     */
    public function content(): string;

    /**
     * 参与缓存的文件路径
     *
     * @access public
     * @return string|null null = 字符串模板或不参与文件缓存
     */
    public function path(): ?string;

    /**
     * 源指纹
     *
     * @access public
     * @return string
     */
    public function fingerprint(): string;

}
