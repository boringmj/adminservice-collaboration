<?php

namespace AdminService;

use function array_key_exists;
use function array_slice;
use function explode;
use function is_numeric;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strpos;
use function substr;
use function trim;

/**
 * 内容协商
 *
 * - 解析 `Accept` 头为「类型 => q」, 支持全通配、子类通配(`type/*`)与 `q=0`(明确拒绝)
 * - 引号内的逗号/分号与反斜杠转义不参与切分; 引号未闭合时退化为按分隔符直接切分
 * - 具体的 vendor JSON(`application/xxx+json`)按标准 `application/json` 参与协商
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
        $json_q=null;
        foreach(self::splitOutsideQuotes((string)$header,',') as $part) {
            $pieces=self::splitOutsideQuotes($part,';');
            $type=strtolower(trim($pieces[0]));
            if($type==='')
                continue;
            $q=1.0;
            foreach(array_slice($pieces,1) as $param) {
                $param=trim($param);
                $pair=explode('=',$param,2);
                if(!isset($pair[1])||strtolower(trim($pair[0]))!=='q')
                    continue;
                $value=trim($pair[1]);
                if(!is_numeric($value))
                    continue;
                $q=(float)$value;
                if($q<0)
                    $q=0.0;
                elseif($q>1)
                    $q=1.0;
            }
            $accept[$type]=$q;
            // vendor JSON 与标准 JSON 等价; 兼容常见的后缀通配 application/*+json
            if((self::normalizeType($type)==='application/json'||$type==='application/*+json')
                &&($json_q===null||$q>$json_q))
                $json_q=$q;
        }
        // vendor 变体只在客户端未显式写 application/json 时补标准 JSON;
        // 显式 q=0 的拒绝不能被其它 JSON 变体覆盖
        if($json_q!==null&&!array_key_exists('application/json',$accept))
            $accept['application/json']=$json_q;
        return $accept;
    }

    /**
     * 归一内容类型
     *
     * - 具体的 `application/xxx+json` 与 `application/json` 等价
     * - 通配类型(如 `application/*+json`)与非 application 的 `+json` 保持原样
     *
     * @access public
     * @param string $type 类型
     * @return string 归一后的类型
     */
    public static function normalizeType(string $type): string {
        $type=strtolower(trim($type));
        if(str_starts_with($type,'application/')&&str_ends_with($type,'+json')&&!str_contains($type,'*'))
            return 'application/json';
        return $type;
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
        $raw=strtolower(trim($type));
        // 先按原始类型精确匹配, 再尝试标准 JSON 等价
        if(array_key_exists($raw,$accept))
            return $accept[$raw]>0;
        $type=self::normalizeType($raw);
        if($type!==$raw&&array_key_exists($type,$accept))
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
     * - 含通配符的候选(如登记表里的全通配项)不参与选择, 由调用方决定
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
            $raw=strtolower(trim($candidate));
            $type=self::normalizeType($raw);
            // 通配候选不参与选择
            if(str_contains($type,'*'))
                continue;
            $major=self::majorType($type);
            if(array_key_exists($raw,$accept)) {
                // 原始类型优先, 保留显式 vendor 类型的独立权重
                $specificity=3;
                $q=$accept[$raw];
            } elseif($type!==$raw&&array_key_exists($type,$accept)) {
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
     * 按分隔符切分, 忽略引号内的分隔符
     *
     * - 双引号内的 `\,`/`\;` 不参与切分, 反斜杠可转义引号或反斜杠
     * - 引号未闭合时退回直接按分隔符切分, 不抛异常
     *
     * @access private
     * @param string $value 待切分字符串
     * @param string $delimiter 单字符分隔符
     * @return array<string>
     */
    private static function splitOutsideQuotes(string $value,string $delimiter): array {
        $parts=array();
        $current='';
        $quoted=false;
        $escaped=false;
        $length=strlen($value);
        for($i=0;$i<$length;$i++) {
            $char=$value[$i];
            if($escaped) {
                $current.=$char;
                $escaped=false;
                continue;
            }
            if($quoted&&$char==='\\') {
                $current.=$char;
                $escaped=true;
                continue;
            }
            if($char==='"') {
                $quoted=!$quoted;
                $current.=$char;
                continue;
            }
            if($char===$delimiter&&!$quoted) {
                $parts[]=$current;
                $current='';
                continue;
            }
            $current.=$char;
        }
        if($quoted)
            return explode($delimiter,$value);
        $parts[]=$current;
        return $parts;
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
