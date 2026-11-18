<?php

namespace AdminService\View\Parser;

use AdminService\Exception;

use function count;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function strlen;
use function substr;
use function substr_count;
use function trim;

/**
 * 模板词法器
 *
 * - 把模板切成文本与标签; 标签内语法由 Parser 处理
 * - 行号按原始源文本统计; 独立行控制标签的清理在 token 上完成, 不改写源文本
 *
 * @access public
 * @package AdminService\View\Parser
 * @version 1.1.0
 */
final class Lexer {

    /**
     * 切分模板
     *
     * @access public
     * @param string $source 模板内容
     * @param string $label 模板标识(用于错误信息)
     * @return array<int,array{type:string,value:string,raw:bool,line:int}>
     * @throws Exception 标签未闭合
     */
    public function tokenize(string $source,string $label='template'): array {
        $tokens=array();
        $offset=0;
        $line=1;
        $pattern='/\{\{\{(.*?)\}\}\}|\{\{!(.*?)\}\}|\{\{(.*?)\}\}/s';
        if(preg_match_all($pattern,$source,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE)===false)
            throw new Exception('Template tokenize failed: '.$label);
        foreach($matches as $match) {
            $start=$match[0][1];
            if($start>$offset) {
                $text=substr($source,$offset,$start-$offset);
                $tokens[]=array('type'=>'text','value'=>$text,'raw'=>false,'line'=>$line);
                $line+=substr_count($text,"\n");
            }
            $raw=false;
            if(isset($match[1])&&$match[1][1]>=0) {
                $value=$match[1][0];
                $raw=true;
            } elseif(isset($match[2])&&$match[2][1]>=0) {
                $value=$match[2][0];
                $raw=true;
            } else {
                $value=$match[3][0]??'';
            }
            $tokens[]=array('type'=>'tag','value'=>trim($value),'raw'=>$raw,'line'=>$line);
            $line+=substr_count($match[0][0],"\n");
            $offset=$start+strlen($match[0][0]);
        }
        if($offset<strlen($source))
            $tokens[]=array('type'=>'text','value'=>substr($source,$offset),'raw'=>false,'line'=>$line);
        return $this->cleanStandaloneTokens($tokens);
    }

    /**
     * 清理独立行控制标签的缩进与换行
     *
     * - 标签独占一行时, 前一段文本只保留到行尾换行, 后一段文本削掉行首的缩进与换行
     *
     * @access private
     * @param array<int,array{type:string,value:string,raw:bool,line:int}> $tokens token 列表
     * @return array<int,array{type:string,value:string,raw:bool,line:int}>
     */
    private function cleanStandaloneTokens(array $tokens): array {
        $count=count($tokens);
        for($index=0;$index<$count;$index++) {
            $token=$tokens[$index];
            if($token['type']!=='tag'||$token['raw'])
                continue;
            if(preg_match('/^\/?(?:if|foreach)(?:\s|$)/',$token['value'])!==1&&$token['value']!=='else')
                continue;
            // 标签须独占一行: 前段文本以换行收尾且行内仅缩进(标签位于源文本起点时视为满足)
            if($index>0
                &&!($tokens[$index-1]['type']==='text'
                    &&preg_match('/(?:\A|\n)[ \t]*\z/',$tokens[$index-1]['value'])===1))
                continue;
            if(!($index+1<$count&&$tokens[$index+1]['type']==='text'
                &&preg_match('/^[ \t]*[\r\n]+/',$tokens[$index+1]['value'])===1))
                continue;
            if($index>0)
                $tokens[$index-1]['value']=preg_replace('/[ \t]+\z/','',$tokens[$index-1]['value']);
            $tokens[$index+1]['value']=preg_replace('/^[ \t]*[\r\n]+/','',$tokens[$index+1]['value']);
        }
        return $tokens;
    }

}
