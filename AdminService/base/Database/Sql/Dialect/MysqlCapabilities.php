<?php

namespace base\Database\Sql\Dialect;

/**
 * MySQL 方言能力
 */
final class MysqlCapabilities implements DialectCapabilitiesInterface {

    /**
     * 是否支持 RETURNING
     *
     * @access public
     * @return bool
     */
    public function supportsReturning(): bool {
        return false;
    }

    /**
     * 是否支持 JSON
     *
     * @access public
     * @return bool
     */
    public function supportsJson(): bool {
        return true;
    }

    /**
     * 是否支持窗口函数
     *
     * @access public
     * @return bool
     */
    public function supportsWindowFunction(): bool {
        return true;
    }

    /**
     * 是否支持保存点
     *
     * @access public
     * @return bool
     */
    public function supportsSavepoint(): bool {
        return true;
    }

}
