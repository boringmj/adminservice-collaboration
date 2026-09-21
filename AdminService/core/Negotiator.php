<?php

namespace AdminService;

use function array_key_exists;
use function array_slice;
use function explode;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function strpos;
use function substr;
use function trim;

/**
 * 内容协商
 *
 * - 解析 `Accept` 头为「类型 => q」, 支持全通配、子类通配(`type/*`)与 `q=0`(明确拒绝)
 * - 选出最优候选: q 高者优先, q 相同者类型更具体者优先, 仍相同则按候选登记顺序
 * - 只做纯计算, 不依赖请求/响应对象, 便于单独测试与复用
 */
final class Negotiator {

    /**
     * 解析Accept头
     *
     * @access public
     * @param string|null $header Accept头
     * @return array<string,float> 类型 => q(写 `q=0` 的类型会保留, 值为 0 表示明确拒绝)
     */
    public static function parse(?string $header): array {
        $accept=array();
        foreach(explode(',',(string)$header) as $part) {
            $pieces=explode(';',$part);
            $type=strtolower(trim($pieces[0]));
            if($type==='')
                continue;
            $q=1.0;
            foreach(array_slice($pieces,1) as $param) {
                $param=trim($param);
                if(str_starts_with($param,'q='))
                    $q=(float)substr($param,2);
            }
            $accept[$type]=$q;
        }
        return $accept;
    }

    /**
     * 判断是否接受某类型
     *
     * - 精确匹配优先; 子类通配与全通配均视为接受; 明确写了 `q=0` 的类型视为拒绝
     *
     * @access public
     * @param array<string,float> $accept 解析结果
     * @param string $type 待判断的类型
     * @return bool
     */
    public static function accepts(array $accept,string $type): bool {
        $type=strtolower($type);
        if(array_key_exists($type,$accept))
            return $accept[$type]>0;
        $major=self::majorType($type);
        if($major!==null&&array_key_exists($major.'/*',$accept))
            return $accept[$major.'/*']>0;
        if(array_key_exists('*/*',$accept))
            return $accept['*/*']>0;
        return false;
    }

    /**
     * 选出最优候选类型
     *
     * - 含通配符的候选(如登记表里的全通配项)不参与选择: 它们是「无偏好」的兜底, 由调用方决定
     *
     * @access public
     * @param array<string,float> $accept 解析结果
     * @param array<string> $candidates 候选类型(按登记顺序传入, 同分时靠前者胜出)
     * @return string|null 没有可接受的候选时返回 null
     */
    public static function best(array $accept,array $candidates): ?string {
        $best=null;
        $best_q=-1.0;
        $best_specificity=0;
        foreach($candidates as $candidate) {
            $type=strtolower($candidate);
            // 通配候选不参与选择
            if(str_contains($type,'*'))
                continue;
            $major=self::majorType($type);
            if(array_key_exists($type,$accept)) {
                $specificity=3;
                $q=$accept[$type];
            } elseif($major!==null&&array_key_exists($major.'/*',$accept)) {
                $specificity=2;
                $q=$accept[$major.'/*'];
            } elseif(array_key_exists('*/*',$accept)) {
                $specificity=1;
                $q=$accept['*/*'];
            } else {
                continue;
            }
            // q=0 表示明确拒绝
            if($q<=0)
                continue;
            if($q>$best_q||($q===$best_q&&$specificity>$best_specificity)) {
                $best=$candidate;
                $best_q=$q;
                $best_specificity=$specificity;
            }
        }
        return $best;
    }

    /**
     * 取主类型(斜杠前的部分)
     *
     * @access private
     * @param string $type 类型
     * @return string|null 不含斜杠时返回 null
     */
    private static function majorType(string $type): ?string {
        $position=strpos($type,'/');
        if($position===false)
            return null;
        return substr($type,0,$position);
    }

}
