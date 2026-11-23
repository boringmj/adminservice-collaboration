<?php

namespace AdminService\Cache;

use function function_exists;
use function is_dir;
use function is_file;
use function is_link;
use function opcache_invalidate;
use function rmdir;
use function scandir;
use function unlink;

/**
 * 目录清理
 *
 * - 顶层路径是符号链接时拒绝, 子项中的链接只删除链接本身
 * - 删除文件后调用 `opcache_invalidate`; 目录不存在视为清理成功
 *
 * @access public
 * @package AdminService\Cache
 * @version 1.0.0
 */
final class DirectoryCleaner {

    /**
     * 构造函数(禁止实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 清理目录
     *
     * @access public
     * @param string $dir 目录
     * @return bool
     */
    public static function clear(string $dir): bool {
        if(is_link($dir))
            return false;
        if(!is_dir($dir))
            return true;
        return self::removeContents($dir)&&@rmdir($dir);
    }

    /**
     * 删除目录内容(保留目录本身)
     *
     * @access private
     * @param string $dir 目录
     * @return bool
     */
    private static function removeContents(string $dir): bool {
        $entries=@scandir($dir);
        if($entries===false)
            return false;
        $ok=true;
        foreach($entries as $entry) {
            if($entry==='.'||$entry==='..')
                continue;
            if(!self::deletePath($dir.'/'.$entry))
                $ok=false;
        }
        return $ok;
    }

    /**
     * 删除文件或目录(递归)
     *
     * @access private
     * @param string $path 路径
     * @return bool
     */
    private static function deletePath(string $path): bool {
        if(is_link($path)||is_file($path)) {
            $ok=@unlink($path);
            if($ok&&function_exists('opcache_invalidate'))
                @opcache_invalidate($path,true);
            return $ok;
        }
        if(!is_dir($path))
            return true;
        return self::removeContents($path)&&@rmdir($path);
    }

}
