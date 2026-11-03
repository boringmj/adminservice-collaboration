<?php

namespace AdminService\Console\Command;

use AdminService\Cache;
use AdminService\Config\Repository;
use AdminService\Console\Input;
use AdminService\Console\Output;
use base\AbstractCommand;
use InvalidArgumentException;

/**
 * 清理编译缓存
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class CacheClearCommand extends AbstractCommand {

    /**
     * 配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 构造方法
     *
     * @access public
     * @param Repository $config 配置仓储
     */
    public function __construct(Repository $config) {
        $this->config=$config;
    }

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string {
        return 'cache clear';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '清理编译缓存(不影响 kv/ 业务缓存池)';
    }

    /**
     * 需要接收值的选项
     *
     * @access public
     * @return array<int,string>
     */
    public function valueOptions(): array {
        return array('target');
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'cache clear [all|config|routes|container]';
    }

    /**
     * 执行命令
     *
     * @access public
     * @param Input $input 已解析参数
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input,Output $output): int {
        try {
            $target=CacheCommandSupport::target($input);
        } catch(InvalidArgumentException $e) {
            $output->error('[ERROR] '.$e->getMessage());
            return self::INVALID;
        }
        $cache=new Cache($this->config);
        try {
            $ok=$cache->clearCompile($target);
        } catch(\Throwable $e) {
            $output->error('[ERROR] Cache clear failed for '.CacheCommandSupport::label($target).': '.$e->getMessage());
            return self::FAILURE;
        }
        if(!$ok) {
            $output->error('[ERROR] Cache clear failed for '.CacheCommandSupport::label($target));
            return self::FAILURE;
        }
        $output->info('Cache cleared: '.CacheCommandSupport::label($target));
        return self::SUCCESS;
    }

}
