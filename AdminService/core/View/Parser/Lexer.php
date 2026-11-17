<?php

namespace AdminService\View\Parser;

use AdminService\Exception;

use function preg_match_all;
use function preg_replace_callback;
use function strlen;
use function substr;
use function substr_count;
use function trim;

/**
 * 模板词法器
 *
 * - 把模板切成文本与标签; 标签内语法由 Parser 处理
 * - 独立行的控制标签按原行为清理缩进与换行
 *
 * @access public
 * @package AdminService\View\Parser
 * @version 1.0.0
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
        $source=$this->cleanStandalone($source);
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
        return $tokens;
    }

    /**
     * 清理独立行控制标签的缩进与换行
     *
     * @access public
     * @param string $source 模板内容
     * @return string
     */
    public function cleanStandalone(string $source): string {
        $result=preg_replace_callback(
            '/^[ \t]*(\{\{\/?(?:if|foreach)[^}]*\}\}|\{\{else\}\})[ \t]*[\r\n]+/m',
            static fn(array $matches): string => trim($matches[1]),
            $source
        );
        return $result??$source;
    }

}
