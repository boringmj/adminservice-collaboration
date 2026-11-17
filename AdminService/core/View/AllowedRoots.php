<?php

namespace AdminService\View;

use base\ConfigInterface;

use function array_filter;
use function array_unique;
use function array_values;
use function dirname;
use function is_array;
use function is_string;
use function ltrim;
use function preg_match;
use function realpath;
use function str_starts_with;

/**
 * 模板允许根目录
 *
 * - 来源为 `app.path`、错误模板所在目录与 `view.allowed_paths`
 * - 相对路径按项目根目录解析; 无法解析的目录会被忽略
 *
 * @access public
 * @package AdminService\View
 * @version 1.0.0
 */
final class AllowedRoots {

    /**
     * 从配置解析允许根目录
     *
     * @access public
     * @param ConfigInterface $config 配置
     * @return array<int,string> 去重后的真实目录
     */
    public static function fromConfig(ConfigInterface $config): array {
        $roots=array();
        $roots[]=self::appPath($config);
        $error_template=$config->get('app.error_template');
        if(is_string($error_template)&&$error_template!=='')
            $roots[]=self::resolve(dirname($error_template));
        $allowed_paths=$config->get('view.allowed_paths',array());
        if(is_array($allowed_paths))
            foreach($allowed_paths as $path)
                if(is_string($path)&&$path!=='')
                    $roots[]=self::resolve($path);
        $roots=array_filter($roots,static fn(?string $root): bool => $root!==null&&$root!=='');
        return array_values(array_unique($roots));
    }

    /**
     * 应用目录真实路径
     *
     * @access public
     * @param ConfigInterface $config 配置
     * @return string|null 未配置或无法解析时为 null
     */
    public static function appPath(ConfigInterface $config): ?string {
        $app_path=$config->get('app.path');
        return is_string($app_path)&&$app_path!==''?self::resolve($app_path):null;
    }

    /**
     * 解析目录真实路径
     *
     * @access private
     * @param string $path 目录
     * @return string|null 无法解析时为 null
     */
    private static function resolve(string $path): ?string {
        if(!self::isAbsolutePath($path))
            $path=dirname(__DIR__,3).'/'.ltrim($path,'/\\');
        $real=realpath($path);
        return is_string($real)&&$real!==''?$real:null;
    }

    /**
     * 判断路径是否为绝对路径
     *
     * @access private
     * @param string $path 路径
     * @return bool
     */
    private static function isAbsolutePath(string $path): bool {
        return preg_match('#^[A-Za-z]:[\\\\/]#',$path)===1
            ||str_starts_with($path,'/')
            ||str_starts_with($path,'\\\\');
    }

}
