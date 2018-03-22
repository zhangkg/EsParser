<?php
/*
|---------------------------------------------------------------
|  Copyright (c) 2018
|---------------------------------------------------------------
| 作者：qieangel2013
| 联系：qieangel2013@gmail.com
| 版本：V1.0
| 日期：2018/3/13
|---------------------------------------------------------------
*/
require_once dirname(__FILE__) . '/positions/PositionCalculator.php';
require_once dirname(__FILE__) . '/processors/DefaultProcessor.php';
class EsParser {
    public $parsed;
    private $Builderarr;
    public $url;
    private $top_hits=0;
    private $top_hits_size=1;
    private $agg;
    private $sort;
    private $index_es='';
    private $type_es='';
    private $version_es='';
    private $count_tmp=0;
    private $count_tmp_filter=0;
    private $count_tmp_range=0;
    private $count_fi=0;
    private $tmp_str='';
    private $tmp_str_filter='';
    private $tmp_fi='';
    private $tmp_str_range='';
    private $tmp_lock='';
    private $tmp_lock_str='';
    private $tmp_lock_fi='';
    private $tmp_lock_range='';
    private $fistgroup='';
    private $limit;
    public $result;
    public $explain;
    /**
     * Constructor. It simply calls the parse() function. 
     * Use the public variable $parsed to get the output.
     * 
     * @param String  $sql           The SQL statement.
     * @param boolean $calcPositions True, if the output should contain [position], false otherwise.
     */
    public function __construct($sql = false,$calcPositions = false,$es_config=array()) {
        if(empty($es_config)){
            $config_err=array(
                'fail' =>1,
                'message'=>'es的配置项不能为空!'
                 );
            $this->result=json_encode($config_err,true);
            return $this->result;
        }else{
            $this->index_es=$es_config['index'];
            $this->type_es=$es_config['type'];
            $this->url=$es_config['url'];
            if(!isset($es_config['version'])){
                $version=$this->getEsData($es_config['url']);
                if($version){
                    if(version_compare($version,'5.0.0', '<')){
                        $this->version_es='2.x';
                    }else if( version_compare($version,'5.0.0', '>=') && version_compare($version,'6.0.0', '<')){
                        $this->version_es='5.x';
                    }else{
                        $this->version_es='6.x';
                    }
                }else{
                    $this->version_es='5.x';
                }
            }else{
                if(trim($es_config['version'])==''){
                    $this->version_es='5.x';
                }else{
                    $this->version_es=$es_config['version'];
                }
            }
            
        }
        if ($sql) {
            $this->parse($sql, $calcPositions);
        }
    }

    /**
     * 
     * @param String  $sql           The SQL statement.
     * @param boolean $calcPositions True, if the output should contain [position], false otherwise.
     * 
     * @return array An associative array with all meta information about the SQL statement.
     */
    
    public function parsesql($sql, $calcPositions = false) {
        $processor = new DefaultProcessor();
        $queries = $processor->process($sql);
        if ($calcPositions) {
            $calculator = new PositionCalculator();
            $queries = $calculator->setPositionsWithinSQL($sql, $queries);
        }
        $this->parsed = $queries;
        return $this->parsed;
    }

    public function parse($sql, $calcPositions = false) {
        $processor = new DefaultProcessor();
        $queries = $processor->process($sql);
        if ($calcPositions) {
            $calculator = new PositionCalculator();
            $queries = $calculator->setPositionsWithinSQL($sql, $queries);
        }
        $this->parsed = $queries;
        return $this->EsBuilder();
        //return $this->parsed;
    }

    private function EsBuilder(){
        //table
        if(isset($this->parsed['FROM']) && !empty($this->parsed['FROM'])){
            $this->table($this->parsed['FROM']);
        }
        //update
        if(isset($this->parsed['UPDATE']) && !empty($this->parsed['UPDATE'])){
            $this->update($this->parsed['UPDATE']);
        }
        //set
        if(isset($this->parsed['SET']) && !empty($this->parsed['SET'])){
            $this->updateset($this->parsed['SET']);
        }
        //delete
        if(isset($this->parsed['DELETE']) && !empty($this->parsed['DELETE'])){
            $this->delete($this->parsed['DELETE']);
        }
        //limit
        if(isset($this->parsed['LIMIT']) && !empty($this->parsed['LIMIT'])){
            $this->limit($this->parsed['LIMIT']);
            if(isset($this->parsed['GROUP']) && !empty($this->parsed['GROUP'])){
                $this->Builderarr['size']=0;
            }else{
                $this->Builderarr['from']=$this->limit['from'] * $this->limit['size'];
                $this->Builderarr['size']=$this->limit['size'];
            }
        }
        //where
        if(isset($this->parsed['WHERE']) && !empty($this->parsed['WHERE'])){
            $this->where($this->parsed['WHERE']);
        }
        //groupby
        if(isset($this->parsed['GROUP']) && !empty($this->parsed['GROUP'])){
            $this->groupby($this->parsed['GROUP']);
            if(!empty($this->agg['aggs'])){
                $this->Builderarr['aggs']=$this->agg['aggs'];
            }
        }
        //orderby
        if(isset($this->parsed['ORDER']) && !empty($this->parsed['ORDER'])){
            $this->orderby($this->parsed['ORDER']);
            if(!empty($this->sort['sort'])){
                $this->Builderarr['sort']=$this->sort['sort'];
            }
        }
        //select
        if(isset($this->parsed['SELECT']) && !empty($this->parsed['SELECT'])){
            $this->select($this->parsed['SELECT']);
        }
        if(!isset($this->Builderarr) && empty($this->Builderarr)){
            $this->Builderarr['query']['match_all']=(object)array();
        }
        //request
        return $this->PostEs($this->Builderarr);
    }

    public function explain(){
        $this->explain=json_encode($this->Builderarr,true);
        return $this->explain;
    }


    private function table($arr){
        if(isset($this->parsed['DELETE']) && !empty($this->parsed['DELETE'])){
            foreach ($arr as $v) {
                if($v['table']){
                    $this->url .="/".$this->index_es."/".$this->type_es."/_delete_by_query?pretty";
                }
            }
        }else{
            foreach ($arr as $v) {
                if($v['table']){
                    $this->url .="/".$this->index_es."/".$this->type_es."/_search?pretty";
                }
            }
        }
        
    }

    private function update($arr){
        foreach ($arr as $v) {
            if($v['table']){
                $this->table=$v['table'];
                $this->url .="/".$this->index_es."/".$this->type_es."/";
            }
        }
    }

    private function delete($arr){
    }

    private function getEsData($url){
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1) ;
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSLVERSION, 3);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, true) ;
            $output = curl_exec($ch);
            if($output === false)  //超时处理
                { 
                    if(curl_errno($ch) == CURLE_OPERATION_TIMEDOUT)  
                    {  
                     my_file_put_contents("getEsData.txt", "时间：".date('Ymd-H:i:s',time())."\r\n错误内容为：curl通过get方式请求{$url}的连接超时\r\n");
                    }  
            }
            curl_close($ch);
           $output=json_decode($output,true);
           if (empty($output)) {
              return array();
            }
            return $output['version']['number'];
    }



    private function PostEs($postdata,$json=true,$token=false){
        $url=$this->url;
        $datastring = json_encode($postdata,true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_URL, $url) ;
        curl_setopt($ch, CURLOPT_POST, 1) ;
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);   //只需要设置一个秒的数量就可以
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSLVERSION, 3);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $datastring);
        if ($json) {
              curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json;',
                    'Content-Length: ' . strlen($datastring))
                );
        }
        if ($token) {
                curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json; charset=utf-8',
                        'Content-Length: ' . strlen($datastring),
                        'Authorization:'.$token
                    )
                );
        }
        $output=curl_exec($ch);
        if($output === false)  //超时处理
            { 
                if(curl_errno($ch) == CURLE_OPERATION_TIMEDOUT)  
                {  
                 file_put_contents("getEsData.txt", "时间：".date('Ymd-H:i:s',time())."\r\n错误内容为：curl通过post方式请求{$this->url}的连接超时\r\n");
                }  
        }
        curl_close($ch);
        $output=json_decode($output,true);
        if (empty($output)) {
              $this->result=json_encode(array(),true);
        }
        if(isset($output['error'])){
            $this->result=json_encode($output,true);
        }else if(isset($this->parsed['UPDATE']) && !empty($this->parsed['UPDATE'])){
            $update_arr=$output['_shards'];
            unset($update_arr['total']);
            $this->result=json_encode($update_arr,true);
        }else if(isset($this->parsed['DELETE']) && !empty($this->parsed['DELETE'])){
            $delete_arr['total']=$output['total'];
            $delete_arr['deleted']=$output['deleted'];
            $delete_arr['successfull']=$output['deleted'];
            $this->result=json_encode($delete_arr,true);
        }else{
            $total_str=$output['hits']['total'];
            if(isset($this->parsed['GROUP']) && !empty($this->parsed['GROUP'])){
                if($output['hits']['hits']){
                    $outputs['result']=array_slice($output['hits']['hits'],-$this->limit['size']);
                }else if(isset($output['aggregations'][$this->fistgroup]['buckets']) && empty($output['aggregations'][$this->fistgroup]['buckets'])){
                    $outputs['result']=$output['aggregations'][$this->fistgroup]['buckets'];
                }else{
                    $outputs['result']=array_slice($output['aggregations'][$this->fistgroup]['buckets'],-$this->limit['size']);
                }
            }else{
                if(isset($output['aggregations']) && !empty($output['aggregations'])){
                    $outputs['result']=$output['aggregations'];
                }else{
                    $outputs['result']=$output['hits']['hits'];
                }
            }
            $outputs['total']=$total_str;
            $this->result=json_encode($outputs,true);
        }
        return $this->result;
        
    }

    private function where($arr){
        for($i=0;$i<count($arr);$i++){
            if($arr[$i]['expr_type']=='bracket_expression'){
                if($arr[$i]['sub_tree']){
                    for($j=0;$j<count($arr[$i]['sub_tree']);$j++){
                        $this->whereext($arr[$i]['sub_tree'],$j);
                    }
                }
            }else{
                $this->whereext($arr,$i);
            }
            
        }
    }

    private function whereext($arr,$i){
        if(!is_numeric($arr[$i]['base_expr'])){
                $lowerstr = strtolower($arr[$i]['base_expr']);
            }else{
                $lowerstr = $arr[$i]['base_expr'];
            }
            switch ($lowerstr) {
                case '=':
                    if(isset($arr[$i+2]['base_expr']) && strtolower($arr[$i+2]['base_expr'])=='or' || isset($arr[$i-2]['base_expr']) && strtolower($arr[$i-2]['base_expr'])=='or'){
                        if(strrpos($arr[$i-1]['base_expr'],".")){
                            $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                            $termk=$term_tmp_arr[1];
                        }else{
                            $termk=$arr[$i-1]['base_expr'];
                        }
                        $tmp_da_str=str_replace('"','',$arr[$i+1]['base_expr']);
                        $tmp_da_str=str_replace("'","",$tmp_da_str);

                        if(isset($this->Builderarr['query']['bool']['filter'][0]) && $this->tmp_lock!='' && $this->tmp_lock!=$lowerstr){
                            if($this->tmp_str_filter==''){
                                $this->count_tmp_filter++;
                            }else if($this->tmp_str_filter!='' && $this->tmp_str_filter!=$termk){
                                $this->count_tmp_filter++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][0]['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                            if($this->tmp_str==''){
                                $this->count_tmp++;
                            }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                                $this->count_tmp++;
                            }
                        }
                        if(isset($this->parsed['UPDATE']) && !empty($this->parsed['UPDATE'])){
                            $this->url .=$tmp_da_str ."/_update?pretty";
                        }else{
                            if(!is_numeric($arr[$i+1]['base_expr']) && $this->version_es=='8.x'){
                                $term['match_phrase'][$termk.'.keyword']['query']=$tmp_da_str;
                                $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_tmp]['bool']['should'][]=$term;
                            }else{
                                $term['match_phrase'][$termk]['query']=$tmp_da_str;
                                $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_tmp]['bool']['should'][]=$term;
                            }
                                unset($term['match_phrase']);
                        }
                        $this->tmp_lock=$lowerstr;
                        $this->tmp_lock_str=$lowerstr;
                    }else if(isset($arr[$i+2]['base_expr']) && strtolower($arr[$i+2]['base_expr'])=='and' || isset($arr[$i-2]['base_expr']) && strtolower($arr[$i-2]['base_expr'])=='and'){
                        if(strrpos($arr[$i-1]['base_expr'],".")){
                            $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                            $termk=$term_tmp_arr[1];
                        }else{
                            $termk=$arr[$i-1]['base_expr'];
                        }
                        $tmp_da_str=str_replace('"','',$arr[$i+1]['base_expr']);
                        $tmp_da_str=str_replace("'","",$tmp_da_str);
                        if(isset($this->Builderarr['query']['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                            if($this->tmp_str==''){
                                $this->count_tmp++;
                            }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                                $this->count_tmp++;
                            }
                        }
                        if(isset($this->parsed['UPDATE']) && !empty($this->parsed['UPDATE'])){
                            $this->url .=$tmp_da_str ."/_update?pretty";
                        }else{
                            if(!is_numeric($arr[$i+1]['base_expr']) && $this->version_es=='8.x'){
                                $term['match_phrase'][$termk.'.keyword']['query']=$tmp_da_str;
                                $this->Builderarr['query']['bool']['must'][$this->count_tmp]['bool']['must'][]=$term;
                            }else{
                                $term['match_phrase'][$termk]['query']=$tmp_da_str;
                                $this->Builderarr['query']['bool']['must'][$this->count_tmp]['bool']['must'][]=$term;
                            }
                                unset($term['match_phrase']);
                        }
                        $this->tmp_lock_str=$lowerstr;
                    }
                    
                    break;
                case 'in':
                    if(strrpos($arr[$i-1]['base_expr'],".")){
                        $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                        $termk=$term_tmp_arr[1];
                    }else{
                        $termk=$arr[$i-1]['base_expr'];
                    }
                    if(isset($this->Builderarr['query']['bool']['filter'][0]) && $this->tmp_lock!='' && $this->tmp_lock!=$lowerstr){
                        if($this->tmp_str_filter==''){
                            $this->count_tmp_filter++;
                        }else if($this->tmp_str_filter!='' && $this->tmp_str_filter!=$termk){
                            $this->count_tmp_filter++;
                        }
                    }
                    if(isset($arr[$i+1]['sub_tree']) && !empty($arr[$i+1]['sub_tree'])){
                        foreach ($arr[$i+1]['sub_tree'] as &$vv) {
                            if(!is_numeric($vv['base_expr']) && $this->version_es=='5.x'){
                                $termk .='.keyword';
                            }
                            $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['terms'][$termk][]=$vv['base_expr'];
                        }
                    }
                    unset($termk);
                    $this->tmp_lock=$lowerstr;
                    break;
                case '>':
                    if(strrpos($arr[$i-1]['base_expr'],".")){
                        $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                        $termk=$term_tmp_arr[1];
                    }else{
                        $termk=$arr[$i-1]['base_expr'];
                    }
                    $tmp_da_str=str_replace('"','',$arr[$i+1]['base_expr']);
                    $tmp_da_str=str_replace("'","",$tmp_da_str);
                    $is_date=strtotime($tmp_da_str)?strtotime($tmp_da_str):false;
                    if(isset($arr[$i+2]['base_expr']) && strtolower($arr[$i+2]['base_expr'])=='or' || isset($arr[$i-2]['base_expr']) && strtolower($arr[$i-2]['base_expr'])=='or'){
                        if(isset($this->Builderarr['query']['bool']['filter'][0]) && $this->tmp_lock!='' && $this->tmp_lock==$lowerstr){
                            if($this->tmp_str_filter==''){
                                $this->count_tmp_filter++;
                            }else if($this->tmp_str_filter!='' && $this->tmp_str_filter!=$termk){
                                $this->count_tmp_filter++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][0]) && $this->tmp_lock_fi!='' && $this->tmp_lock_fi==$lowerstr){
                            if($this->tmp_fi==''){
                                $this->count_fi++;
                            }else if($this->tmp_fi!='' && $this->tmp_fi!=$termk){
                                $this->count_fi++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][0]) && $this->tmp_lock_range!=''){
                            if($this->tmp_str_range==''){
                                $this->count_tmp_range++;
                            }else if($this->tmp_str_range!='' && $this->tmp_str_range!=$termk){
                                $this->count_tmp_range++;
                            }
                        }
                        $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['gt']=$tmp_da_str;
                         if(!isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']="+08:00";
                        }
                    }else{
                        if(isset($this->Builderarr['query']['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                            if($this->tmp_str==''){
                                $this->count_tmp++;
                            }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                                $this->count_tmp++;
                            }
                        }
                        $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['gt']=$tmp_da_str;
                        if(!isset($this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']="+08:00";
                        }
                    }
                    $this->tmp_str=$termk;
                    $this->tmp_lock_str=$lowerstr;
                    $this->tmp_lock=$lowerstr;
                    $this->tmp_lock_range=$lowerstr;
                    $this->tmp_lock_fi=$lowerstr;
                    break;
                case '>=':
                    if(strrpos($arr[$i-1]['base_expr'],".")){
                        $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                        $termk=$term_tmp_arr[1];
                    }else{
                        $termk=$arr[$i-1]['base_expr'];
                    }
                    $tmp_da_str=str_replace('"','',$arr[$i+1]['base_expr']);
                    $tmp_da_str=str_replace("'","",$tmp_da_str);
                    $is_date=strtotime($tmp_da_str)?strtotime($tmp_da_str):false;
                    if(isset($arr[$i+2]['base_expr']) && strtolower($arr[$i+2]['base_expr'])=='or' || isset($arr[$i-2]['base_expr']) && strtolower($arr[$i-2]['base_expr'])=='or'){
                        if(isset($this->Builderarr['query']['bool']['filter'][0]) && $this->tmp_lock!='' && $this->tmp_lock==$lowerstr){
                            if($this->tmp_str_filter==''){
                                $this->count_tmp_filter++;
                            }else if($this->tmp_str_filter!='' && $this->tmp_str_filter!=$termk){
                                $this->count_tmp_filter++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][0]) && $this->tmp_lock_fi!='' && $this->tmp_lock_fi==$lowerstr){
                            if($this->tmp_fi==''){
                                $this->count_fi++;
                            }else if($this->tmp_fi!='' && $this->tmp_fi!=$termk){
                                $this->count_fi++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][0]) && $this->tmp_lock_range!='' ){
                            if($this->tmp_str_range==''){
                                $this->count_tmp_range++;
                            }else if($this->tmp_str_range!='' && $this->tmp_str_range!=$termk){
                                $this->count_tmp_range++;
                            }
                        }
                        $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['gte']=$tmp_da_str;
                         if(!isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']="+08:00";
                        }
                    }else{
                        if(isset($this->Builderarr['query']['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                            if($this->tmp_str==''){
                                $this->count_tmp++;
                            }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                                $this->count_tmp++;
                            }
                        }
                        $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['gte']=$tmp_da_str;
                        if(!isset($this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']="+08:00";
                        }
                    }
                    $this->tmp_str=$termk;
                    $this->tmp_lock_str=$lowerstr;
                    $this->tmp_lock=$lowerstr;
                    $this->tmp_lock_range=$lowerstr;
                    $this->tmp_lock_fi=$lowerstr;
                    break;
                case '<':
                    if(strrpos($arr[$i-1]['base_expr'],".")){
                        $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                        $termk=$term_tmp_arr[1];
                    }else{
                        $termk=$arr[$i-1]['base_expr'];
                    }
                    $tmp_da_str=str_replace('"','',$arr[$i+1]['base_expr']);
                    $tmp_da_str=str_replace("'","",$tmp_da_str);
                    $is_date=strtotime($tmp_da_str)?strtotime($tmp_da_str):false;
                    if(isset($arr[$i+2]['base_expr']) && strtolower($arr[$i+2]['base_expr'])=='or' || isset($arr[$i-2]['base_expr']) && strtolower($arr[$i-2]['base_expr'])=='or'){
                        if(isset($this->Builderarr['query']['bool']['filter'][0]) && $this->tmp_lock!='' && $this->tmp_lock==$lowerstr){
                            if($this->tmp_str_filter==''){
                                $this->count_tmp_filter++;
                            }else if($this->tmp_str_filter!='' && $this->tmp_str_filter!=$termk){
                                $this->count_tmp_filter++;
                            }
                        }
                         if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][0]) && $this->tmp_lock_fi!='' && $this->tmp_lock_fi==$lowerstr){
                            if($this->tmp_fi==''){
                                $this->count_fi++;
                            }else if($this->tmp_fi!='' && $this->tmp_fi!=$termk){
                                $this->count_fi++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][0]) && $this->tmp_lock_range!=''){
                            if($this->tmp_str_range==''){
                                $this->count_tmp_range++;
                            }else if($this->tmp_str_range!='' && $this->tmp_str_range!=$termk){
                                $this->count_tmp_range++;
                            }
                        }
                        $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['lt']=$tmp_da_str;
                         if(!isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']="+08:00";
                        }
                    }else{
                        if(isset($this->Builderarr['query']['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                            if($this->tmp_str==''){
                                $this->count_tmp++;
                            }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                                $this->count_tmp++;
                            }
                        }
                        $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['lt']=$tmp_da_str;
                        if(!isset($this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']="+08:00";
                        }
                    }
                    
                    $this->tmp_str=$termk;
                    $this->tmp_lock_str=$lowerstr;
                    $this->tmp_lock=$lowerstr;
                    $this->tmp_lock_range=$lowerstr;
                    $this->tmp_lock_fi=$lowerstr;
                    break;
                case '<=':
                    if(strrpos($arr[$i-1]['base_expr'],".")){
                        $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                        $termk=$term_tmp_arr[1];
                    }else{
                        $termk=$arr[$i-1]['base_expr'];
                    }
                    $tmp_da_str=str_replace('"','',$arr[$i+1]['base_expr']);
                    $tmp_da_str=str_replace("'","",$tmp_da_str);
                    $is_date=strtotime($tmp_da_str)?strtotime($tmp_da_str):false;
                    if(isset($arr[$i+2]['base_expr']) && strtolower($arr[$i+2]['base_expr'])=='or' || isset($arr[$i-2]['base_expr']) && strtolower($arr[$i-2]['base_expr'])=='or'){
                        if(isset($this->Builderarr['query']['bool']['filter'][0]) && $this->tmp_lock!='' && $this->tmp_lock==$lowerstr){
                            if($this->tmp_str_filter==''){
                                $this->count_tmp_filter++;
                            }else if($this->tmp_str_filter!='' && $this->tmp_str_filter!=$termk){
                                $this->count_tmp_filter++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][0]) && $this->tmp_lock_fi!='' && $this->tmp_lock_fi==$lowerstr){
                            if($this->tmp_fi==''){
                                $this->count_fi++;
                            }else if($this->tmp_fi!='' && $this->tmp_fi!=$termk){
                                $this->count_fi++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][0]) && $this->tmp_lock_range!='' ){
                            if($this->tmp_str_range==''){
                                $this->count_tmp_range++;
                            }else if($this->tmp_str_range!='' && $this->tmp_str_range!=$termk){
                                $this->count_tmp_range++;
                            }
                        }
                        $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['lte']=$tmp_da_str;
                         if(!isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][$this->count_tmp_range]['range'][$termk]['time_zone']="+08:00";
                        }
                    }else{
                        if(isset($this->Builderarr['query']['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                            if($this->tmp_str==''){
                                $this->count_tmp++;
                            }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                                $this->count_tmp++;
                            }
                        }
                        $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['lte']=$tmp_da_str;
                        if(!isset($this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']) && $is_date){
                            $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']="+08:00";
                        }
                    }
                    $this->tmp_str=$termk;
                    $this->tmp_lock_str=$lowerstr;
                    $this->tmp_lock=$lowerstr;
                    $this->tmp_lock_range=$lowerstr;
                    $this->tmp_lock_fi=$lowerstr;
                    break;
                case 'like':
                    if(strrpos($arr[$i-1]['base_expr'],".")){
                        $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                        $termk=$term_tmp_arr[1];
                    }else{
                        $termk=$arr[$i-1]['base_expr'];
                    }
                    $tmp_la_str=str_replace('"','',$arr[$i+1]['base_expr']);
                    $tmp_la_str=str_replace("'","",$tmp_la_str);
                    if(isset($arr[$i+2]['base_expr']) && strtolower($arr[$i+2]['base_expr'])=='or' || isset($arr[$i-2]['base_expr']) && strtolower($arr[$i-2]['base_expr'])=='or'){
                        if(isset($this->Builderarr['query']['bool']['filter'][0]) && $this->tmp_lock!='' && $this->tmp_lock!=$lowerstr){
                            if($this->tmp_str_filter==''){
                                $this->count_tmp_filter++;
                            }else if($this->tmp_str_filter!='' && $this->tmp_str_filter!=$termk){
                                $this->count_tmp_filter++;
                            }
                        }
                        if(isset($this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][0]) && $this->tmp_lock_fi!='' && $this->tmp_lock_fi!=$lowerstr){
                            if($this->tmp_fi==''){
                                $this->count_fi++;
                            }else if($this->tmp_fi!='' && $this->tmp_fi!=$termk){
                                $this->count_fi++;
                            }
                        }
                         if(!is_numeric($arr[$i+1]['base_expr']) && $this->version_es=='8.x'){
                            $term['match'][$termk.'.keyword']=str_replace("%","",$tmp_la_str);
                            $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][]=$term;
                        }else{
                            $term['match'][$termk]=str_replace("%","",$tmp_la_str);
                            $this->Builderarr['query']['bool']['filter'][$this->count_tmp_filter]['bool']['must'][$this->count_fi]['bool']['should'][]=$term;
                        }
                    }else{
                        if(isset($this->Builderarr['query']['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                            if($this->tmp_str==''){
                                $this->count_tmp++;
                            }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                                $this->count_tmp++;
                            }
                        }
                        if(!is_numeric($arr[$i+1]['base_expr']) && $this->version_es=='8.x'){
                            $term['match'][$termk.'.keyword']=str_replace("%","",$tmp_la_str);
                            $this->Builderarr['query']['bool']['must'][$this->count_tmp]['bool']['must'][]=$term;
                        }else{
                            $term['match'][$termk]=str_replace("%","",$tmp_la_str);
                            $this->Builderarr['query']['bool']['must'][$this->count_tmp]['bool']['must'][]=$term;
                        }
                    }
                    unset($term['match']);
                    $this->tmp_lock_str=$lowerstr;
                    $this->tmp_lock=$lowerstr;
                    $this->tmp_lock_fi=$lowerstr;
                    break;
                case 'between':
                     if(strrpos($arr[$i-1]['base_expr'],".")){
                        $term_tmp_arr=explode(".",$arr[$i-1]['base_expr']);
                        $termk=$term_tmp_arr[1];
                    }else{
                        $termk=$arr[$i-1]['base_expr'];
                    }
                    if(isset($this->Builderarr['query']['bool']['must'][0]) && $this->tmp_lock_str!='' && $this->tmp_lock_str!=$lowerstr){
                        if($this->tmp_str==''){
                            $this->count_tmp++;
                        }else if($this->tmp_str!='' && $this->tmp_str!=$termk){
                            $this->count_tmp++;
                        }
                    }
                    $tmp_da_str=str_replace('"','',$arr[$i+1]['base_expr']);
                    $tmp_da_str=str_replace("'","",$tmp_da_str);
                    $is_date=strtotime($tmp_da_str)?strtotime($tmp_da_str):false;
                    $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['gte']=$tmp_da_str;
                    if(!isset($this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']) && $is_date){
                        $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['time_zone']="+08:00";
                    }
                    $tmp_da_str=str_replace('"','',$arr[$i+3]['base_expr']);
                    $tmp_da_str=str_replace("'","",$tmp_da_str);
                    $this->Builderarr['query']['bool']['must'][$this->count_tmp]['range'][$termk]['lte']=$tmp_da_str;
                    $this->tmp_str=$termk;
                    $this->tmp_lock_str=$lowerstr;
                    break;
            }
    }



    private function groupby($arr){
        $aggs= array();
        for ($j=0; $j <count($arr); $j++) { 
            if(strrpos($arr[$j]['base_expr'],".")){
                $term_tmp_arr=explode(".",$arr[$j]['base_expr']);
                $termk=$term_tmp_arr[1];
                $termk_tmp=$termk;
            }else{
                $termk=$arr[$j]['base_expr'];
                $termk_tmp=$termk;
            }
            $tmmp=0;
            if(!is_numeric($termk) && ($this->version_es=='5.x' || $this->version_es=='6.x')){
                $termk .='.keyword';
            }
            if(isset($this->fistgroup) && $this->fistgroup==''){
                $this->fistgroup=$termk_tmp.'_group';
            }
            if(isset($this->parsed['SELECT']) && !empty($this->parsed['SELECT'])){
                foreach ($this->parsed['SELECT'] as $v) {
                    $this->top_hits=1;
                    if(strrpos($v['base_expr'],"*")){
                        //$this->top_hits=1;
                    }else{
                        if($v['expr_type']=='aggregate_function'){
                            $lowerstr = strtolower($v['base_expr']);
                            switch ($lowerstr) {
                                case 'count':
                                    $agg[$j][$termk_tmp.'_group']['terms']['field']=$termk;
                                    $agg[$j][$termk_tmp.'_group']['terms']['size']=$this->limit['from']*$this->limit['size']==0?10:($this->limit['from'] + 1 )*$this->limit['size'];
                                    if(strrpos($v['sub_tree'][0]['base_expr'],".")){
                                        $term_tmp_arrs=explode(".",$v['sub_tree'][0]['base_expr']);
                                        $cardinalitys[$v['alias']['name']]['cardinality']['field']=$term_tmp_arrs[1];
                                    }else{
                                        $cardinalitys[$v['alias']['name']]['cardinality']['field']=$v['sub_tree'][0]['base_expr'];
                                    }
                                    $tmmp=1;
                                    $agggs[$j][$termk_tmp.'_group']['aggs']=$cardinalitys;
                                    $aggs[$j]=array_merge_recursive($agg[$j], $agggs[$j]);
                                    break;
                                case 'sum':
                                    $agg[$j][$termk_tmp.'_group']['terms']['field']=$termk;
                                    $agg[$j][$termk_tmp.'_group']['terms']['size']=$this->limit['from']*$this->limit['size']==0?10:($this->limit['from'] + 1 )*$this->limit['size'];
                                    if(strrpos($v['sub_tree'][0]['base_expr'],".")){
                                        $term_tmp_arrs=explode(".",$v['sub_tree'][0]['base_expr']);
                                        $cardinalitys[$v['alias']['name']]['sum']['field']=$term_tmp_arrs[1];
                                    }else{
                                        $cardinalitys[$v['alias']['name']]['sum']['field']=$v['sub_tree'][0]['base_expr'];
                                    }
                                    $tmmp=1;
                                    $agggs[$j][$termk_tmp.'_group']['aggs']=$cardinalitys;
                                    $aggs[$j]=array_merge_recursive($agg[$j], $agggs[$j]);
                                    break;
                                case 'min':
                                    $agg[$j][$termk_tmp.'_group']['terms']['field']=$termk;
                                    $agg[$j][$termk_tmp.'_group']['terms']['size']=$this->limit['from']*$this->limit['size']==0?10:($this->limit['from'] + 1 )*$this->limit['size'];
                                    if(strrpos($v['sub_tree'][0]['base_expr'],".")){
                                        $term_tmp_arrs=explode(".",$v['sub_tree'][0]['base_expr']);
                                        $cardinalitys[$v['alias']['name']]['min']['field']=$term_tmp_arrs[1];
                                    }else{
                                        $cardinalitys[$v['alias']['name']]['min']['field']=$v['sub_tree'][0]['base_expr'];
                                    }
                                    $tmmp=1;
                                    $agggs[$j][$termk_tmp.'_group']['aggs']=$cardinalitys;
                                    $aggs[$j]=array_merge_recursive($agg[$j], $agggs[$j]);
                                    break;
                                case 'max':
                                    $agg[$j][$termk_tmp.'_group']['terms']['field']=$termk;
                                    $agg[$j][$termk_tmp.'_group']['terms']['size']=$this->limit['from']*$this->limit['size']==0?10:($this->limit['from'] + 1 )*$this->limit['size'];
                                    if(strrpos($v['sub_tree'][0]['base_expr'],".")){
                                        $term_tmp_arrs=explode(".",$v['sub_tree'][0]['base_expr']);
                                        $cardinalitys[$v['alias']['name']]['max']['field']=$term_tmp_arrs[1];
                                    }else{
                                        $cardinalitys[$v['alias']['name']]['max']['field']=$v['sub_tree'][0]['base_expr'];
                                    }
                                    $tmmp=1;
                                    $agggs[$j][$termk_tmp.'_group']['aggs']=$cardinalitys;
                                    $aggs[$j]=array_merge_recursive($agg[$j], $agggs[$j]);
                                    break;
                                case 'avg':
                                    $agg[$j][$termk_tmp.'_group']['terms']['field']=$termk;
                                    $agg[$j][$termk_tmp.'_group']['terms']['size']=$this->limit['from']*$this->limit['size']==0?10:($this->limit['from'] + 1 )*$this->limit['size'];
                                    if(strrpos($v['sub_tree'][0]['base_expr'],".")){
                                        $term_tmp_arrs=explode(".",$v['sub_tree'][0]['base_expr']);
                                        $cardinalitys[$v['alias']['name']]['avg']['field']=$term_tmp_arrs[1];
                                    }else{
                                        $cardinalitys[$v['alias']['name']]['avg']['field']=$v['sub_tree'][0]['base_expr'];
                                    }
                                    $tmmp=1;
                                    $agggs[$j][$termk_tmp.'_group']['aggs']=$cardinalitys;
                                    $aggs[$j]=array_merge_recursive($agg[$j], $agggs[$j]);
                                    break;
                            }
                            if(isset($this->parsed['ORDER']) && !empty($this->parsed['ORDER'])){
                                foreach ($this->parsed['ORDER'] as $vv) {
                                    if($vv['base_expr']==$v['alias']['name']){
                                        $aggs[$j][$termk_tmp.'_group']['terms']['order'][$vv['base_expr']]=$vv['direction'];
                                         if(strrpos($v['sub_tree'][0]['base_expr'],".")){
                                            $term_tmp_arrss=explode(".",$v['sub_tree'][0]['base_expr']);
                                            $aggs[$j][$termk_tmp.'_group']['aggs'][$vv['base_expr']][$lowerstr]['field']=$term_tmp_arrs[1];
                                        }else{
                                            $aggs[$j][$termk_tmp.'_group']['aggs'][$vv['base_expr']][$lowerstr]['field']=$v['sub_tree'][0]['base_expr'];
                                        }
                                    }
                                }
                            }
                            if($this->top_hits){
                                $aggs[$j][$termk_tmp.'_group']['aggs']['top']['top_hits']['size']=$this->top_hits;
                            }
                        }
                    }
            }
        }
            if($tmmp==0){
                 $agg[$j][$termk_tmp.'_group']['terms']['field']=$termk;
                 $agg[$j][$termk_tmp.'_group']['terms']['size']=$this->limit['from']*$this->limit['size']==0?10:($this->limit['from'] + 1 )*$this->limit['size'];
                $agggs[$j][$termk_tmp.'_group']['aggs']=(object)array();
                $aggs[$j]=array_merge_recursive($agg[$j], $agggs[$j]);
                unset($aggs[$j][$termk_tmp.'_group']['aggs']);
            }   
        }
        $this->agg['aggs']=$this->inverted($aggs);
    }

    private function orderby($arr){
        if(isset($this->parsed['SELECT']) && !empty($this->parsed['SELECT'])){
            foreach ($this->parsed['SELECT'] as $v) {
                    foreach ($arr as $kk=>$vv) {
                        if($v['alias']){
                            if($v['alias']['name']==$vv['base_expr']){
                                unset($arr[$kk]);
                            }
                        }
                    }
            }

        }
        foreach ($arr as &$va) {
            if(strrpos($va['base_expr'],".")){
                $term_tmp_arr=explode(".",$va['base_expr']);
                $termk=$term_tmp_arr[1];
            }else{
                $termk=$va['base_expr'];
            }
            $this->sort['sort'][][$termk]['order']=$va['direction'];
        }
    }

    private function limit($arr){
        if(!$arr['offset']){
            $this->limit['from']=0;
        }else{
            $this->limit['from']=$arr['offset'];
        }
        $this->limit['size']=$arr['rowcount'];
    }

    private function inverted($arr){
        for($i=count($arr)-1;$i>=0;$i--){
            if($i>0){
                $arr[$i-1]['aggs']=$arr[$i];
            }
        }
        if(empty($arr)){
            return array();
        }else{
            return $arr[0];
        }
    }

    private function select($arr){
        if(isset($this->parsed['GROUP']) && !empty($this->parsed['GROUP'])){
        }else{
            foreach ($arr as $k => $v) {
                if($v['expr_type']=='aggregate_function'){
                     if(strrpos($v['sub_tree'][0]['base_expr'],".")){
                        $term_tmp_arrs=explode(".",$v['sub_tree'][0]['base_expr']);
                        if($term_tmp_arrs[1]=='*'){
                            continue;
                        }
                        if(isset($v['alias']['name'])){
                            $this->Builderarr['aggs'][$v['alias']['name']]['stats']['field']=$term_tmp_arrs[1];
                        }else{
                            $this->Builderarr['aggs'][$v['sub_tree'][0]['base_expr']]['stats']['field']=$term_tmp_arrs[1];
                        }
                        
                    }else{
                        if($v['sub_tree'][0]['base_expr']=='*'){
                            continue;
                        }
                        if(isset($v['alias']['name'])){
                            $this->Builderarr['aggs'][$v['alias']['name']]['stats']['field']=$v['sub_tree'][0]['base_expr'];
                        }else{
                            $this->Builderarr['aggs'][$v['sub_tree'][0]['base_expr']]['stats']['field']=$v['sub_tree'][0]['base_expr'];
                        }  
                    }
                }
            }
        }        
    }

    private function updateset($arr){
        foreach ($arr as &$v) {
            if($v['sub_tree']){
                $tmp_sub[$v['sub_tree'][0]['base_expr']]=$v['sub_tree'][2]['base_expr'];
                $this->Builderarr['doc']=$tmp_sub;
                unset($tmp_sub);
            }
        }
    }
}
?>