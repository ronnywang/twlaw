<?php

date_default_timezone_set('Asia/Taipei');

class MyErrorException extends Exception{
    public function setMessage($message){
        $this->message = $message;
    }

}


class Exporter
{
    public static function array_insert(&$array, $idx, $value)
    {
        $array = array_merge(array_slice($array, 0, $idx - 1), [$value], array_slice($array, $idx - 1));
    }

    public static function file_get_contents($file)
    {
        error_log($file);
        return file_get_contents($file);
    }

    public static function getCommitDate($versions)
    {
        $versions = implode(';',  $versions);
        if (preg_match('#中華民國(.*)年(.*)月(.*)日(制定|全文修正|修正|廢止|期滿廢止|停止適用)#u', $versions, $matches)) {
        } else {
            throw new MyErrorException("找不到最後時間: " . $versions);
        }
        return [sprintf("%04d%02d%02d", $matches[1] + 1911, $matches[2], $matches[3]), $matches[4]];
    }

    protected static $_law_cache = null;

    /**
     * searchLawID  查詢現在的法條跟前面的版本差異
     * 
     * @param mixed $law_data 要查詢的法律資料
     * @param mixed $law_id 法律代碼
     * @param mixed $commit_at 法條三讀日期（用在回傳 {三讀日期}-{條號} 的法條代碼）
     * @param mixed $action 最近一次修法動作（制定、原文修正、修正、廢止...）
     * @param mixed $law_ver 
     * @param mixed $prev_idx 
     * @static
     * @access protected
     * @return array - 法條代碼
     *               - 修法動作
     *               - 前法版本
     *               - 現法版本
     *               - 上一條法條是第幾條
     */
    protected static function searchLawID($law_data, $law_id, $commit_at, $action, $law_ver, $prev_idx)
    {
        if (in_array($action, ['制定', '全文修正'])) {
            return [$commit_at . '-' . $law_data->rule_no, $action, '', $law_ver, $prev_idx + 1, ''];
        }
        if (!property_exists(self::$_law_cache, $law_id) or !property_exists(self::$_law_cache->{$law_id}, 'previous')) {
            throw new Exception('no previous version');
        }
        $prev_version = self::$_law_cache->{$law_id}->previous;
        $prev_lines = self::$_law_cache->{$law_id}->{$prev_version};
        while (true) {
            if (!array_key_exists($prev_idx, $prev_lines)) {
                echo json_encode($prev_lines, JSON_UNESCAPED_UNICODE) . "\n";
                echo json_encode($law_data, JSON_UNESCAPED_UNICODE) . "\n";
                echo $commit_at . "\n";
                throw new Exception('no prev_ix=' . $prev_idx);
            }
            $lawlinedata = $prev_lines[$prev_idx];
            if ($lawlinedata->{'動作'} == '刪除' and $lawlinedata->{'內容'} != '（刪除）') {
            } else {
                break;
            }
            $prev_idx ++;
        }
        if (self::isSameContent($lawlinedata->{'內容'}, $law_data->content)) {
            return [$lawlinedata->{'法條代碼'}, '未變更', $lawlinedata->{'前法版本'}, $lawlinedata->{'此法版本'}, $prev_idx + 1, ''];
        }
        if (property_exists($law_data, 'diff_reason')) {
            $reason = $law_data->diff_reason;
        } else {
            $reason = '';
        }
        if ($law_data->diff_act == '(修正)') {
            return [$lawlinedata->{'法條代碼'}, '修正', $lawlinedata->{'此法版本'}, $law_ver, $prev_idx + 1, $reason];
        }

        if ($law_data->diff_act == '(刪除)') {
            if ($law_data->content == '（刪除）') {
                return [$lawlinedata->{'法條代碼'}, '刪除', $lawlinedata->{'此法版本'}, $law_ver, $prev_idx + 1, $reason];
            } else {
                return [$lawlinedata->{'法條代碼'}, '刪除', $lawlinedata->{'此法版本'}, '', $prev_idx + 1, $reason];
            }
        }
        if ($law_data->diff_act == '(增訂)') {
            return [$commit_at . '-' . $law_data->rule_no, '增訂', '', $law_ver, $prev_idx, $reason];
        }

        echo 'law_data=' . json_encode($law_data, JSON_UNESCAPED_UNICODE) . "\n";
        echo 'lawlinedata=' . json_encode($lawlinedata, JSON_UNESCAPED_UNICODE) . "\n";
        echo 'prev:lawlinedata=' . json_encode($prev_lines[$prev_idx - 1], JSON_UNESCAPED_UNICODE) . "\n";
        echo self::filterStr($law_data->content) . "\n";
        echo self::filterStr($lawlinedata->{'內容'}) . "\n";
        throw new Exception("找不到對應的法律: " . $law_data->content);
    }

    public static function addData($type, $data)
    {
        $law_id = $data->{'法律代碼'};
        if ($type == 'lawline') {
            $version_id = $data->{'法律版本代碼'};
            if (is_null(self::$_law_cache)) {
                self::$_law_cache = new StdClass;
            }
            if (!property_exists(self::$_law_cache, $law_id)) {
                self::$_law_cache = new StdClass;
                self::$_law_cache->{$law_id} = new StdClass;
            }
            if (!property_exists(self::$_law_cache->{$law_id}, $version_id)) {
                self::$_law_cache->{$law_id}->{$version_id} = [];
            }
            self::$_law_cache->{$law_id}->{$version_id}[] = $data;
        } elseif ($type == 'lawver') {
            $version_id = $data->{'法律版本代碼'};
            self::$_law_cache->{$law_id}->previous = $version_id;
        }
        file_put_contents(__DIR__ . "/law-data/{$type}.jsonl", json_encode($data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    public static function filterStr($str)
    {
        $str = preg_replace('#\s+#', '', $str);
        $str = preg_replace('/[。：，、；]/u', '', $str);
        // 內政部組織法
        $str = str_replace('關於地方行政組織計畫之釐訂改進審核事項', '關於地方行政組織計劃之釐訂改進審核事項', $str); // XXX
        $str = str_replace('關於合作社登記之審查事項', '關於合作社登記之審核事項', $str); // XXX
        $str = str_replace('承長官之命令', '承長官之命', $str); // XXX
        $str = str_replace('專員二十人至卅人薦派', '專員二十人至三十人薦派', $str); // XXX
        $str = str_replace('內政部因事務上之需要', '內政部因事務上之必要', $str); // XXX
        return $str;
    }

    public static function isSameContent($a, $b)
    {
        return self::filterStr($a) == self::filterStr($b);
    }

    public static function handleCommits($commits, $laws)
    {
        usort($commits, function($a, $b) { return $a[0] - $b[0]; });

        error_log('writing');

        $law_id = $commits[0][1];

        $law = new StdClass;
        $law->{'法律代碼'} = '01' . $commits[0][1];
        $law->{'最新名稱'} = $laws[$law_id]['name'];
        $law->{'其他名稱'} = [];
        $law->{'現行版本號'} = '';
        $law->{'種類'} = '現行母法';
        $law->{'狀態'} = $laws[$law_id]['type'];
        $law->{'立法院代碼'} = $law_id;

        foreach ($commits as $time_obj) {
            list($commit_at, $id, $title, $versions, $action) = $time_obj;

            $lawver = new StdClass;
            $lawver->{'法律代碼'} = $id;
            $lawver->{'版本種類'} = '三讀';
            $lawver->{'法律版本代碼'} = $commit_at . '-三讀';
            $lawver->{'法律名稱'} = $title;
            if ($title != $law->{'最新名稱'} and !in_array($title, $law->{'其他名稱'})) {
                $law->{'其他名稱'}[] = $title;
            }
            $law->{'現行版本號'} = $commit_at . '-三讀';
            if (!$obj = json_decode(self::file_get_contents("law_cache/{$id}-{$versions[0]}.json"))) {
                throw new Exception("{$id}-{$versions[0]} failed");
            }
            $prev_idx = 0;
            $mother_level = '';

            if (in_array($commit_at, ['19310822', '19360124']) and $id == '01423') {
                self::array_insert($obj->law_data, 5, (object)[
                    'note' => '',
                    'rule_no' => '第五條',
                    'content' => '對於外國總統君主應服之服裝，與對於國民政府主席同。',
                    'relates' => [],
                ]); // XXX
            }

            $output_idx = 0;
            for ($law_idx = 0; $law_idx < count($obj->law_data); $law_idx ++) {
                $law_data = clone $obj->law_data[$law_idx];
                if (property_exists($law_data, 'section_name')) {
                    $mother_level = $law_data->section_name;
                    continue;
                }

                if (property_exists($law_data, 'content')) {
                    $law_data->content = trim(str_replace('　', '  ', $law_data->content));
                    $law_data->content = str_replace('﹙刪除﹚', '（刪除）', $law_data->content);
                    $law_data->content = preg_replace('#\[附表[^]]+\]$#', '', $law_data->content);
                    if ($commit_at == '19310214' and $id == '01013' and $law_data->content == '外交部政務次表、常任次長，輔助部長處理部務。') {
                        continue; // XXX: 外交部組織法 25-02-14
                    }
                    if ($commit_at == '19361002' and $id == '01017' and $law_data->content == '教育部設科長十四人至十六人，科員八十人至一百零六人，承長官之命，分掌各科事務。') {
                        continue; // 教育部組織法 中華民國25年10月02日
                    }
                    if ($commit_at == '19421219' and $id == '01017' and $law_data->content == '教育部設督學八人至十六人，視察員十六人至二十四人，視察及指導全國教育事宜。') {
                        continue; // 教育部組織法 中華民國29年10月31日
                    }
                    if (in_array($commit_at, ['19310822', '19360124']) and $id == '01423' and $law_data->content == '准尉以上各員服大禮服、禮服時，應用黑漆皮靴，服公服或常服時，得用黑皮靴，當雨雪時，得用黑皮長靴。') {
                        continue; // 海軍服裝條例 中華民國20年08月22日
                    }
                    if (in_array($commit_at, ['19540803', '19551230']) and $id == '01508' and strpos($law_data->content, '百分之二十由省統籌分配，在直轄市應')) {
                        continue; // 財政收支劃分法 中華民國43年08月03日
                    }
                    if (in_array($commit_at, ['19540803', '19551230']) and $id == '01508' and strpos($law_data->content, '百分之十由省統籌分配')) {
                        continue; // 財政收支劃分法 中華民國43年08月03日
                    }
                    if (in_array($commit_at, ['19540803', '19551230']) and $id == '01508' and strpos($law_data->content, '謂適應地方自治事業之需要經議會立法課征之稅但不得以已征貨物稅或特產稅之貨物為課征對')) {
                        continue; // 財政收支劃分法 中華民國43年08月03日
                    }
                }
                if (property_exists($obj, 'diff_table')) {
                    if (property_exists($obj->diff_table, $law_data->rule_no)) {
                        $law_data->diff_act = $obj->diff_table->{$law_data->rule_no};
                    }
                    if (property_exists($obj->diff_table, $law_data->rule_no . ':理由')) {
                        $law_data->diff_reason  = $obj->diff_table->{$law_data->rule_no . ':理由'};
                    }
                }
                if ($commit_at == '19310627' and $id == '01017' and $law_data->rule_no == '第二十四條') {
                    $prev_idx --;
                    $law_data->diff_act = '(增訂)'; // XXX
                } elseif ($commit_at == '19750509' and $id == '01132' and $law_data->rule_no == '第六十條') {
                    $law_data->diff_act = '(修正)';
                    $law_data->diff_reason = '「社會部」修正為「內政部」並更改條次。'; // XXX
                } elseif ($commit_at == '19370702' and $id == '01432' and in_array($law_data->rule_no, [
                    '第一百十二條', '第一百十三條', '第一百十四條',
                ])) {
                    $prev_idx --;
                    $law_data->diff_act = '(增訂)'; // XXX
                }

                $lawline = new StdClass;
                $lawline->{'法律代碼'} = $id;
                $lawline->{'法律版本代碼'} = $commit_at . '-三讀';
                list($lawline_id, $law_action, $prev_ver, $current_ver, $prev_idx, $note) = self::searchLawID($law_data, $id, $commit_at, $action, $law->{'現行版本號'}, $prev_idx);
                $lawline->{'法條代碼'} = $lawline_id;
                $lawline->{'順序'} = $output_idx ++;
                $lawline->{'條號'} = $law_data->rule_no;
                $lawline->{'母層級'} = $mother_level;
                $lawline->{'動作'} = $law_action;
                $lawline->{'內容'} = '';
                $lawline->{'前法版本'} = $prev_ver;
                $lawline->{'此法版本'} = $current_ver;
                $lawline->{'說明'} = $note;
                if ($lawline->{'動作'} == '刪除') {
                    $lawline->{'條號'} = $law_data->rule_no;
                    if ($law_data->content == '（刪除）') {
                        $lawline->{'內容'} = $law_data->content;
                    } else {
                        $law_idx --;
                    }
                    unset($obj->diff_table->{$law_data->rule_no});
                } else if (property_exists($law_data, 'rule_no') and property_exists($law_data, 'content')) {
                    $lawline->{'條號'} = $law_data->rule_no;
                    $lawline->{'內容'} = $law_data->content;
                } else {
                    print_r($law_data);
                    throw new Exception('不明狀況');
                }
                self::addData('lawline', $lawline);
            }
            self::addData('lawver', $lawver);
        }
        self::addData('law', $law);
    }


    public function trim($str)
    {
        $str = str_replace('&nbsp;', '', $str);
        return trim($str, " \t\n" . html_entity_decode('&nbsp;'));
    }

    public function genContent($origin_obj, $laws)
    {
        $obj = clone $origin_obj;
        unset($obj->types); // types 用不到，跳過

        $ret = new StdClass;
        $ret->content = '';
        foreach ($obj->law_data as $record) {
            if (property_exists($record, 'section_name')) {
                $title = $record->section_name;
                $ret->content .= $title . "\n";
                $ret->content .= str_repeat('=', mb_strwidth($title)) . "\n";
                continue;
            }

            if (!property_exists($record, 'rule_no')) {
                print_r($record);
                exit;
            }
            $title = $record->rule_no;
            if (property_exists($record, 'note')) {
                $title .= " " . $record->note;
            }
            $ret->content .= $title . "\n";
            $ret->content .= str_repeat('-', mb_strwidth($title)) . "\n";
            $ret->content .= str_replace("\n", "  \n", $record->content) . "  \n";

            if (property_exists($obj, 'law_reasons') and array_key_exists($record->rule_no, $obj->law_reasons)) {
                $ret->content .= "> 理由：" . str_replace("\n", "\n\n> ", trim($obj->law_reasons->{$record->rule_no})) . "\n\n";
                //unset($obj->law_reasons->{$record->rule_no});
            }

            if (property_exists($record, 'relates') and is_object($record->relates)) {
                foreach (array('引用條文', '被引用條文') as $type) {
                    if (property_exists($record->relates, $type)) {
                        foreach ($record->relates->{$type} as $relate_law) {
                            if (!array_key_exists($relate_law->law_no, $laws)) {
                                throw new Exception("找不到 {$relate_law->law_no}");
                            }
                            $relate_url = sprintf("../../%s/%s/%s.md",
                                $laws[$relate_law->law_no]['categories'][0][0],
                                $laws[$relate_law->law_no]['categories'][0][1],
                                $laws[$relate_law->law_no]['name']
                            );
                            $relate_records = array();
                            foreach ($relate_law->numbers as $number) {
                                if (array_key_exists($number, $laws[$relate_law->law_no]['rule_note'])) {
                                    $anchor = $laws[$relate_law->law_no]['rule_note'][$number];
                                } else {
                                    $anchor = $number;
                                }
                                $relate_records[] = "[{$number}]({$relate_url}#{$anchor})";
                            }
                            $ret->content .= "> {$type}: {$relate_law->law_name} " . implode(' ', $relate_records) . "\n\n";
                        }
                    }
                }
            }

            $ret->content .= "\n";
            $ret->content .= "\n";
        }
        $ret->content = trim($ret->content);;
        unset($obj->law_data);

        if (property_exists($obj, 'law_reasons') and !count($obj->law_reasons)) {
            unset($obj->law_reasons);
        }
            unset($obj->law_reasons); // XXX: 姓名條例對不到

        list($commit_at, $action) = self::getCommitDate($obj->versions);
        $ret->commit_at = $commit_at;
        $ret->action = $action;
        $ret->commit_log = $obj->title . ' ' . implode(',', array_map(function($a) {
            preg_match('#中華民國(.*)年(.*)月(.*)日(.*)#', $a, $matches);
            return sprintf("%d/%d/%d %s", $matches[1] + 1911, $matches[2], $matches[3], $matches[4]);
        }, $obj->versions));

        unset($obj->versions);

        if (property_exists($obj, 'law_history')) {
            $ret->commit_authors = array();
            foreach ($obj->law_history as $history) {
                if (property_exists($history, '主提案')) {
                    $ret->commit_authors[] = $history->{'主提案'};
                }
            }
            $ret->commit_log .= "\n\n" . json_encode($obj->law_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            unset($obj->law_history);
        }

        if (property_exists($obj, 'deprecated_reason')) {
            $ret->commit_log .= "\n\n" . '廢除原因:' . $obj->deprecated_reason;
            unset($obj->deprecated_reason);
        }

        unset($obj->title);

        if (json_encode($obj) != '{}') {
            echo 'ret=';
            var_dump($ret);
            echo 'obj=';
            var_dump($obj);
            throw new MyErrorException("還有 obj 未處理");
        }
        return $ret;
    }

    public function parseDiffHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        foreach ($doc->getElementsByTagName('table') as $table_dom) {
            if ($table_dom->getElementsByTagName('td')->item(0)->nodeValue != '版本法名稱') {
                continue;
            }
            $ret = new Stdclass;
            foreach ($table_dom->getElementsByTagName('tr') as $tr_dom) {
                $td_doms = $tr_dom->getElementsByTagName('td');
                if ($td_doms->item(0)->nodeValue == '版本法名稱') {
                    continue;
                } else if ($td_doms->item(0)->nodeValue == '版本條文') {
                    continue;
                } else {
                    $td_dom = $td_doms->item(0);
                    $txt = $td_dom->childNodes->item(0)->nodeValue;
                    if ($txt == '理由') {
                        $ret->{$rule_no . ':理由'} = trim(str_replace('　', '  ', $td_doms->item(1)->nodeValue));
                        continue;
                    }
                    $rule_no = str_replace(' ', '', $txt);

                    $act = $td_dom->childNodes->item(1)->nodeValue;
                    $ret->{$rule_no} = $act;
                }
            }
            return $ret;
        }
        throw new Exception('wrong');
    }

    public function parseDeprecatedHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') == 'artipud_RS_2') {
                return trim($td_dom->nodeValue);
            }
        }
        return '';
        throw new MyErrorException("找不到 td.artipud_RS_2");
    }

    public function parseRelateHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        $ret = array();
        $table_dom = $doc->getElementsByTagName('font')->item(0)->parentNode->nextSibling;
        while ($table_dom = $table_dom->nextSibling) {
            $group = null;
            $law = null;
            foreach ($table_dom->getElementsByTagName('font') as $font_dom) {
                if ($font_dom->getAttribute('color') == 'blue') {
                    $text = trim($font_dom->nodeValue);
                    $text = trim($text, ':');
                    if (in_array($text, array('引用條文', '被引用條文'))) {
                        $group = $text;
                        $law = null;
                        if (array_key_exists($group, $ret)) {
                            throw new Exception("$group 出現兩次");
                        }
                        $ret[$group] = array();
                    } elseif (preg_match('#^(.*)\((.*)\)$#', $text, $matches)) {
                        if (is_null($group)) {
                            print_r($ret);
                            echo $doc->saveHTML($table_dom);
                            throw new Exception("錯誤");
                        }
                        $law_name = $matches[1];
                        $law = $matches[2];
                        $ret[$group][$law] = array(
                            'law_no' => $law,
                            'law_name' => $law_name,
                            'numbers' => array(),
                        );
                    } else {
                        echo $doc->saveHTML($font_dom);
                        throw new Exception("不明格式");
                    }
                } elseif ($font_dom->getAttribute('color') == 'c000ff') {
                    if (is_null($group) or is_null($law)) {
                        echo $doc->saveHTML($font_dom);
                        throw new Exception("不明格式");
                    }
                    $ret[$group][$law]['numbers'][] = trim($font_dom->nodeValue);
                } else {
                    echo $doc->saveHTML($font_dom);
                    throw new Exception("不明格式");
                }
            }
        }
        return array_map('array_values', $ret);
    }

    public function parseLawHTML($content, $filepath)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $table_dom = $doc->getElementById('C_box')->getElementsByTagName('table')->item(1);

        $lines = array();

        foreach ($table_dom->childNodes as $tr_dom) {
            $td_doms = $tr_dom->childNodes;

            // 正常來說 <tr> 裡面只會有一個 <td>
            if ($td_doms->length != 1) {
                continue;
                error_log($filepath);
                echo $doc->saveHtml($tr_dom);
                throw new MyErrorException("td 應該只有一個");
            }
            $td_dom = $td_doms->item(0);
            $name = null;

            $line = new StdClass;
            while (true) {
                $pos = 0;
                while ($n = $td_dom->childNodes->item($pos) and $n->nodeName == '#text' and trim($n->nodeValue) == '') {
                    $pos ++;
                }

                // 如果 <td> 內純文字開頭，表示進入法案內容，就可以跳出了
                if ($pos >= $td_dom->childNodes->length or $td_dom->childNodes->item($pos)->nodeName == '#text') {
                    $line->rule_no = trim(str_replace(' ', '', $name));
                    $line->content = $this->trim($td_dom->nodeValue);
                    $line->relates = new StdClass;
                    foreach ($td_dom->getElementsByTagName('img') as $img_dom) {
                        if ($img_dom->getAttribute('src') != "/lglaw/images/relate.png") {
                            continue;
                        }
                        $relate_id = explode('?', $img_dom->parentNode->getAttribute('href'))[1];
                        if (json_encode($line->relates) != '{}') {
                            print_r($line);
                            exit;
                        }
                        //$line->relates = self::parseRelateHTML(self::file_get_contents(__DIR__ . '/laws/relate/' . $relate_id . '.html'));
                    }
                    $lines[] = $line;
                    break;
                }

                // 第一個 node 一定是 <font> 並且裡面會有章節條名稱
                $cnode = $td_dom->childNodes->item($pos);
                if ($cnode->nodeName == 'font') {
                    $pos ++;
                    if (!is_null($name)) {
                        $line->section_name = $name;
                        $lines[] = $line;
                        $line = new StdClass;
                    }
                    $name = $cnode->nodeValue;

                    // 下一個 node 如果是純文字，就是備註，要不然就是法條內容
                    $cnode = $td_dom->childNodes->item($pos);
                    if ($cnode->nodeName == '#text') {
                        $line->note = $this->trim($cnode->nodeValue);
                        $pos ++;
                    }
                }

                // 下一個一定是 <table>
                $cnode = $td_dom->childNodes->item($pos);
                if ($cnode->nodeName != 'table') {
                    echo $doc->saveHtml($tr_dom);
                    throw new MyErrorException("tr 下應該要有 <font/> 和 <table />");
                }

                $td_dom = $cnode->getElementsByTagName('td')->item(1);

            }
        }
        return $lines;
    }

    public function parseHistoryHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $records = array();
        foreach ($doc->getElementsByTagName('table') as $table_dom) {
            if ($table_dom->getAttribute('class') == 'sumtab04') {
                foreach ($table_dom->getElementsByTagName('div') as $div_dom) {
                    if ($div_dom->getAttribute('id') == 'all') {
                        continue;
                    }
                    foreach ($div_dom->getElementsByTagName('tr') as $tr_dom) {
                        $td_doms = $tr_dom->getElementsByTagName('td');
                        if (!$td_doms->item(0)) {
                            continue;
                        }
                        if ($td_doms->item(0)->getAttribute('class') != 'sumtd1') {
                            continue;
                        }
                        $record = new StdClass;
                        $record->{'進度'} = $td_doms->item(0)->nodeValue;
                        $record->{'會議日期'} = $td_doms->item(1)->nodeValue;
                        if (!$td_doms->item(2)) {
                            throw new MyErrorException("找不到立法紀錄的表格");
                        }
                        $record->{'立法紀錄'} = $td_doms->item(2)->nodeValue;
                        if ($td_doms->item(2)->getElementsByTagName('a')->item(0)) {
                            $record->{'立法紀錄連結'} = $td_doms->item(2)->getElementsByTagName('a')->item(0)->getAttribute('href');
                        }
                        if ($p = $this->trim($td_doms->item(3)->nodeValue)) {
                            $record->{'主提案'} = iconv('UTF-8', 'UTF-8//IGNORE', $p);
                        }
                        $record->{'關係文書'} = array();
                        if (!$td_doms->item(4)) {
                            $a_doms = array();
                        } else {
                            $a_doms = $td_doms->item(4)->getElementsByTagName('a');
                        }
                        foreach ($a_doms as $a_dom) {
                            $href = $a_dom->getAttribute('href');
                            if (strpos($href, '/') === 0) {
                                $href = 'http://lis.ly.gov.tw' . $href;
                            }
                            if (!$text_dom = $a_dom->nextSibling or $text_dom->nodeType != XML_TEXT_NODE) {
                                $record->{'關係文書'}[] = array(
                                    'null',
                                    $href,
                                );
                                continue;
                            }
                            $text = trim($text_dom->nodeValue, html_entity_decode('&nbsp;') . "\n\r");
                            $text = preg_replace('#\s#', '', $text);
                            if (!preg_match('#^\((.*)\),?$#', $text, $matches)) {
                                $text = '';
                            } else {
                                $text = $matches[1];
                            }
                            $record->{'關係文書'}[] = array(
                                $text,
                                $href,
                            );
                        }
                        $records[] = $record;
                    }
                }
            }

        }
        return $records;
    }

    public function parseReasonHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $reasons = new StdClass;
        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') != 'artipud_RS_2') {
                continue;
            }
            $reason = $td_dom->nodeValue;
            $tr_dom = $td_dom->parentNode;
            while ($tr_dom = $tr_dom->previousSibling) {
                if ($tr_dom->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $font_dom = $tr_dom->getElementsByTagName('font')->item(0);
                if (!$font_dom) {
                    continue;
                }
                if ($font_dom->getAttribute('color') == '#8600B3') {
                    break;
                }
            }
            if (!$font_dom) {
                throw new MyErrorException("找不到 {$reason} 對應到的 .artiupd_TH_1 是哪一條");
            }
            $reasons->{trim($font_dom->nodeValue)} = $reason;
        }
        return $reasons;
    }

    public function main()
    {
        $laws = array();
        $fp = fopen('laws.csv', 'r');
        fgetcsv($fp);
        while ($rows = fgetcsv($fp)) {
            list($id, $name, $type) = $rows;
            $laws[$id] = array(
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'rule_note' => array(),
            );
        }

        fclose($fp);

        $fp = fopen('laws-category.csv', 'r');
        fgetcsv($fp);
        while ($rows = fgetcsv($fp)) {
            list($category1, $category2, $type, $id, $name)= $rows;
            $laws[$id]['categories'][] = array(
                $category1, $category2
            );
        }
        fclose($fp);

        error_log("parsing");
        $fp = fopen('laws-versions.csv', 'r');
        fgetcsv($fp);
        $commits = array();

        while ($rows = fgetcsv($fp)) {
            list($id, $title, $versions, $types) = $rows;

            if (count($_SERVER['argv']) > 1) {
                if ($id != $_SERVER['argv'][1] and $title != $_SERVER['argv'][1]) {
                    continue;
                }
            }

            $versions = preg_replace('#[; \n]*$#', '', $versions);
            $versions = explode(';', $versions);
            if (strlen($types)) {
                $types = explode(';', $types);
            } else {
                $types = array();
            }

            // 先抓本文
            $filepath = "laws/{$id}/{$versions[0]}.html";
            $content = self::file_get_contents($filepath);
            $doc = new DOMDocument;
            @$doc->loadHTML($content);
            foreach ($doc->getElementsByTagName('td') as $td_dom) {
                if ($td_dom->getAttribute('class') == 'law_NA') {
                    $title = $td_dom->childNodes->item(0)->nodeValue;
                    break;
                }
            }
            $obj = new StdClass;
            $obj->title = $title;
            $obj->versions = $versions;
            $obj->types = $types;
            try {
                $obj->law_data = $this->parseLawHTML($content, $filepath);
            } catch (MyErrorException $e) {
                $e->setMessage("{$title} {$versions[0]} {$e->getMessage()}");
                throw $e;
            }

            foreach ($obj->law_data as $record) {
                if (property_exists($record, 'rule_no')) {
                    if (property_exists($record, 'note')) {
                        $laws[$id]['rule_note'][$record->rule_no] = $record->rule_no . '-' . preg_replace('#[\()]#', '', $record->note);
                    } else  {
                        $laws[$id]['rule_note'][$record->rule_no] = $record->rule_no;
                    }
                }
            }
            foreach ($types as $type) {
                try {
                    if ($type == '異動條文') {
                        // do nothing, 因為異動條文直接 diff 就可以得到了
                    } elseif ($type == '立法歷程') {
                        $content = self::file_get_contents("laws/{$id}/{$versions[0]}-立法歷程.html");
                        $obj->law_history = $this->parseHistoryHTML($content);
                    } elseif ($type == '異動條文及理由') {
                        $content = self::file_get_contents("laws/{$id}/{$versions[0]}-異動條文及理由.html");
                        $obj->law_reasons = $this->parseReasonHTML($content);
                    } elseif ($type == '廢止理由') {
                        $content = self::file_get_contents("laws/{$id}/{$versions[0]}-{$type}.html");
                        $obj->deprecated_reason = $this->parseDeprecatedHTML($content);
                    } elseif ($type == '新舊條文對照表') {
                        $content = self::file_get_contents("laws/{$id}/{$versions[0]}-{$type}.html");
                        $obj->diff_table = $this->parseDiffHTML($content);
                    } else {
                        throw new Exception("TODO {$type} 未處理");
                    }
                } catch (MyErrorException $e) {
                    $e->setMessage("{$title} {$versions[0]} {$type} {$e->getMessage()}");
                    throw $e;
                } catch (Exception $e) {
                    throw new Exception("{$title} {$versions[0]} {$type} {$e->getMessage()}");
                }
            }

            try {
                list($commit_at, $action) = self::getCommitDate($obj->versions);
            } catch (Exception $e) {
                self::addData('error', ['id' => $id, 'title' => $title, 'message' => $e->getMessage()]);
            }

            if (!json_encode($obj)) {
                throw new Exception("{$title} " .date('c', $commit_at));
            }
            file_put_contents("law_cache/{$id}-{$versions[0]}.json", json_encode($obj));
            if (count($commits) and $id != $commits[0][1]) {
                try {
                    self::handleCommits($commits, $laws);
                } catch (Exception $e) {
                    self::addData('error', ['id' => $id, 'title' => $title, 'message' => $e->getMessage()]);
                }
                $commits = [];
            }
            $commits[] = array($commit_at, $id, $title, $versions, $action);
        }
        fclose($fp);

        try {
            self::handleCommits($commits, $laws);
        } catch (Exception $e) {
            self::addData('error', ['id' => $id, 'title' => $title, 'message' => $e->getMessage()]);
        }
    }
}

$e = new Exporter;
$e->main();
