<?php

namespace AdminService\Console\Command;

use AdminService\Console\Input;
use InvalidArgumentException;

use function array_key_exists;
use function is_string;
use function strtolower;
use function trim;

/**
 * 编译缓存命令公共目标解析
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class CacheCommandSupport {

    /**
     * 全部目标
     * @var array<int,string>
     */
    public const TARGETS=array('config','routes','container');

    /**
     * 从参数解析目标
     *
     * @access public
     * @param Input $input 已解析参数
     * @return string|null null = 全部
     * @throws InvalidArgumentException 目标非法
     */
    public static function target(Input $input): ?string {
        if($input->hasOption('target')) {
            $target=$input->option('target');
            if(!is_string($target)||trim($target)==='')
                throw new InvalidArgumentException('Cache target requires a value.');
            return self::normalize($target);
        }
        $target=$input->argument(0);
        if($target===null)
            return null;
        if(trim($target)==='')
            throw new InvalidArgumentException('Cache target requires a value.');
        return self::normalize($target);
    }

    /**
     * 归一化目标
     *
     * @access public
     * @param string $target 目标
     * @return string|null null = 全部
     * @throws InvalidArgumentException 目标非法
     */
    public static function normalize(string $target): ?string {
        $target=strtolower(trim($target));
        if($target===''||$target==='all')
            return null;
        if($target==='route')
            $target='routes';
        if(!array_key_exists($target,array('config'=>true,'routes'=>true,'container'=>true)))
            throw new InvalidArgumentException('Cache target is invalid: '.$target);
        return $target;
    }

    /**
     * 目标显示名
     *
     * @access public
     * @param string|null $target 目标
     * @return string
     */
    public static function label(?string $target): string {
        return $target===null?'all':$target;
    }

}
