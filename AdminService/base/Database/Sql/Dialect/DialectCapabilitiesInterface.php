<?php

namespace base\Database\Sql\Dialect;

/**
 * 方言能力接口
 */
interface DialectCapabilitiesInterface {

    /**
     * 是否支持返回
     * @return bool
     */
    public function supportsReturning(): bool;

    /**
     * 是否支持JSON
     * @return bool
     */
    public function supportsJson(): bool;

    /**
     * 是否支持窗口函数
     * @return bool
     */
    public function supportsWindowFunction(): bool;

    /**
     * 是否支持保存点
     * @return bool
     */
    public function supportsSavepoint(): bool;

}
