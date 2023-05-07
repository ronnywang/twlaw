<?php

class Crawler
{
    protected $curl;

    protected function http($url, $post_params = null)
    {
        for ($i = 0; $i < 3; $i ++) {
            curl_setopt($this->curl, CURLOPT_URL, $url);
            if (!is_null($post_params)) {
                $postfields = implode('&', array_map(function($k) use ($post_params) {
                    return urlencode($k) . '=' . urlencode($post_params[$k]);
                }, array_keys($post_params)));
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postfields);
            } else {
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, null);
	    }
	    curl_setopt($this->curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $content = curl_exec($this->curl);
            $info = curl_getinfo($this->curl);
            if ($info['http_code'] != 200) {
                if ($i == 2) {
                    throw new Exception("抓取 {$url} 失敗, code = {$info['http_code']}");
                }
                error_log("抓取 {$url} 失敗, code = {$info['http_code']}，等待 10 秒後重試");
                sleep(10);
                continue;
            }

            if (strpos($content, 'can not load swap') or strpos($content, 'can not open for read')) {
                throw new Exception("抓取 {$url} 失敗, error");
            }
            break;
        }
        return $content;
    }

    public function crawlCategory()
    {
        error_log("抓取法案分類清單");

        $categories = array();

        $content = $this->http('https://lis.ly.gov.tw/lglawc/lglawkm');
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        $category_link = null;

        // 找到分類瀏覽網址
        foreach ($doc->getElementsByTagName('img') as $img_dom) {
            if ($img_dom->getAttribute('alt') == '分類瀏覽') {
                $category_link = $img_dom->parentNode->getAttribute('href');
                break;
            }
        }

        if (is_null($category_link)) {
            throw new Exception("從 https://lis.ly.gov.tw/lglawc/lglawkm 找不到分類瀏覽網址");
        }

        $content = $this->http('https://lis.ly.gov.tw' . $category_link);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        foreach ($doc->getElementsByTagName('font') as $font_dom) {
            if (!preg_match('#^\((\d+)\)$#', $font_dom->nodeValue, $matches)) {
                continue;
            }
            $category = trim($font_dom->previousSibling->nodeValue);
            $a_dom = $font_dom->parentNode;
            $href = $a_dom->getAttribute('href');
            $count = trim($matches[1]);

            $categories[] = [$category, '', $href, $count];
        }
        return $categories;
    }

    public function getLawCategories()
    {
        if (!file_exists('laws-category.csv')) {
            return array();
        }

        $law_categories = array();

        $fp = fopen('laws-category.csv', 'r');
        $columns = fgetcsv($fp);
        while ($rows = fgetcsv($fp)) {
            list($c1, $c2, $status, $law_id) = $rows;
            $law_categories[implode(',', array($c1, $c2, $law_id))] = $rows;
        }
        fclose($fp);
        return $law_categories;
    }

    /**
     * getNextPageDoc 從現在這一頁的 $doc 找出下一頁的內容，如果沒有下一頁傳 null
     * 
     * @param mixed $doc 
     * @access public
     * @return void
     */
    public function getNextPageDoc($doc)
    {
        // 看看有沒有下一頁
        if (!$form_dom = $doc->getElementsByTagName('form')->item(0)) {
            return null;
        }
        $has_next_input = false;
        $params = array();
        foreach ($form_dom->getElementsByTagName('input') as $input_dom) {
            if ($input_dom->getAttribute('type') == 'image' and $input_dom->getAttribute('src') == '/lglaw/images/page_next.png') {
                $has_next_input = true;
                $params[$input_dom->getAttribute('name') . '.x'] = 5;
                $params[$input_dom->getAttribute('name') . '.y'] = 5;
            } elseif ($input_dom->getAttribute('type') == 'hidden') {
                $params[$input_dom->getAttribute('name')] = $input_dom->getAttribute('value');
            }
        }

        if (!$has_next_input) {
            return null;
        }

        $url = 'https://lis.ly.gov.tw' . $form_dom->getAttribute('action');

        $content = $this->http($url, $params);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        return $doc;
    }

    public function crawlLawList($categories)
    {
        // 先與現在的 laws.csv 比較一下數量
        $category_count = array();
        foreach (self::getLawCategories() as $law_category) {
            list($category, $category2 ) = $law_category;
            if (!array_key_exists($category . '-' . $category2, $category_count)) {
                $category_count[$category . '-' . $category2] = 0;
            }
            $category_count[$category . '-' . $category2] ++;
        }

        $count = count($categories);
        foreach ($categories as $no => $rows) {
            list($category, $category2, $url, $count) = $rows;
            if (array_key_exists($category . '-' . $category2, $category_count) and $category_count[$category . '-' . $category2] == $count) {
                error_log("{$category} / {$category2} 數量吻合，不需要重抓");
                continue;
            }

            $laws = array();
            error_log("抓取法案 {$no}/{$count} {$category} > {$category2} 分類法案");

            $url = 'https://lis.ly.gov.tw' . $url;
            $content = $this->http($url);
            $doc = new DOMDocument;
            @$doc->loadHTML($content);

            // 先把廢止法連結拉出來備用
            $other_links = array();
            foreach ($doc->getElementsByTagName('a') as $a_dom) {
                if (strpos($a_dom->nodeValue, '廢止法') === 0) {
                    $other_links['廢止'] = $a_dom->getAttribute('href');
                } elseif (strpos($a_dom->nodeValue, '停止適用法') === 0) {
                    $other_links['停止適用'] = $a_dom->getAttribute('href');
                }
            }

            // 先爬現行法
            while (true) {
                foreach ($doc->getElementsByTagName('a') as $a_dom) {
                    if (strpos($a_dom->getAttribute('href'), '/lglawc/lawsingle') === 0) {
                        for ($i = 0; $i < 3; $i ++) {
                            try {
                                $this->crawlLaw($category, $category2, "現行", $a_dom->nodeValue, $a_dom->getAttribute('href'));
                                break;
                            } catch (Exception $e) {
                                error_log("crawlLaw {$a_dom->nodeValue} failed, retry");

                            }
                        }
                    }
                }

                if (!$doc = self::getNextPageDoc($doc)) {
                    break;
                }
            }

            // 再爬其他法
            foreach ($other_links as $other_type => $other_link) {
                $url = 'https://lis.ly.gov.tw' . $other_link;
                error_log($url);
                $content = $this->http($url);
                $doc = new DOMDocument;
                @$doc->loadHTML($content);
                while (true) {
                    foreach ($doc->getElementsByTagName('a') as $a_dom) {
                        if (strpos($a_dom->getAttribute('href'), '/lglawc/lawsingle') === 0) {
                            $this->crawlLaw($category, $category2, $other_type, $a_dom->nodeValue, $a_dom->getAttribute('href'));
                        }
                    }

                    if (!$doc = self::getNextPageDoc($doc)) {
                        break;
                    }
                }
            }
        }
    }

    public function crawlLaw($category, $category2, $status, $title, $url)
    {
        error_log("抓取條文 {$category} > {$category2} > {$title} ({$status}) 資料");

        $url = 'https://lis.ly.gov.tw' . $url;
        $content = $this->http($url);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        // 先取出法條 ID
        $law_id = null;
        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') == 'law_NA' and preg_match('#\((\d+)\)$#', trim($td_dom->nodeValue), $matches)) {
                $law_id = $matches[1];
                break;
            }
        }
        if (!file_exists(__DIR__ . "/laws/{$law_id}")) {
            mkdir(__DIR__ . "/laws/{$law_id}");
        }


        if (is_null($law_id)) {
            throw new Exception("從 $url 找不到法條代碼");
        }

        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') !== 'version_0') {
                continue;
            }
            $versions = array($td_dom->getElementsByTagName('a')->item(0)->nodeValue);
            $law_url = $td_dom->getElementsByTagName('a')->item(0)->getAttribute('href');
            while ($td_dom = $td_dom->parentNode->nextSibling) {
                $versions[] = $td_dom->nodeValue;
            }

            // 先抓全文 
            $url = 'https://lis.ly.gov.tw' . $law_url;
            $content = $this->http($url);
            file_put_contents(__DIR__ . "/laws/{$law_id}/{$versions[0]}.html", $content);
            $this->fetchRelate($content);

            // 再從全文中找出異動條文和理由及歷程
            $law_doc = new DOMDocument;
            @$law_doc->loadHTML($content);
            $btn_map = array(
                'yellow_btn01' => '異動條文及理由',
                'yellow_btn02' => '立法歷程',
                'yellow_btn03' => '異動條文',
                'yellow_btn04' => '廢止理由',
                'yellow_btn05' => '新舊條文對照表',
            );
            $types = array();
            foreach ($law_doc->getElementsByTagName('img') as $img_dom) {
                foreach ($btn_map as $id => $name) {
                    if ($img_dom->getAttribute('src') == "/lglaw/images/{$id}.png"){
                        $url = 'https://lis.ly.gov.tw' . $img_dom->parentNode->getAttribute('href');
                        $content = $this->http($url);
                        file_put_contents(__DIR__ . "/laws/{$law_id}/{$versions[0]}-{$name}.html", $content);
                        $this->fetchRelate($content);

                        $types[] = $name;
                    }
                }
            }
            self::addLawVersion($law_id, $title, $versions, $types);
        }

        self::addLaw($category, $category2, $status, $law_id, $title);
    }

    public static function addLawVersion($law_id, $title, $versions, $types)
    {
        $law_versions = array();

        if (file_exists('laws-versions.csv')) {
            $fp = fopen('laws-versions.csv', 'r');
            $columns = fgetcsv($fp);

            while ($rows = fgetcsv($fp)) {
                $law_versions[$rows[0] . '-' . $rows[2]] = $rows;
            }
            fclose($fp);
        }

        $versions = implode(';', $versions);
        $types = implode(';', $types);
        $law_versions[$law_id . '-' . $versions] = array(
            $law_id, $title, $versions, $types,
        );

        uksort($law_versions, function($k1, $k2) {
            list($id1, $k1) = explode('-', $k1);
            list($id2, $k2) = explode('-', $k2);
            $k1 = explode(';', $k1);
            $k2 = explode(';', $k2);

            while (count($k1) and count($k2)) {
                $v1 = array_pop($k1);
                preg_match('#^中華民國(\d+)年(\d+)月(\d+)日#', $v1, $matches);
                $v1 = sprintf("%05d%03d%02d%02d", $id1, $matches[1], $matches[2], $matches[3]);

                $v2 = array_pop($k2);
                preg_match('#^中華民國(\d+)年(\d+)月(\d+)日#', $v2, $matches);
                $v2 = sprintf("%05d%03d%02d%02d", $id2, $matches[1], $matches[2], $matches[3]);

                if ($r = strcmp($v1, $v2)) {
                    return $r;
                }
            }
            return 0;
        });

        $fp = fopen('laws-versions.csv', 'w');
        fputcsv($fp, array('代碼', '法條名稱', '發布時間', '包含資訊'));
        foreach ($law_versions as $rows) {
            fputcsv($fp, $rows);
        }
        fclose($fp);
    }

    public static function addLaw($category, $category2, $status, $law_id, $title)
    {
        $laws = array();
        if (file_exists('laws.csv')) {
            $fp = fopen('laws.csv', 'r');
            fgetcsv($fp);
            while ($rows = fgetcsv($fp)) {
                $laws[$rows[0]] = $rows;
            }
            fclose($fp);
        }

        $laws[$law_id] = array($law_id, $title, $status);
        ksort($laws);

        $fp = fopen('laws.csv', 'w');
        fputcsv($fp, array('代碼', '法條名稱', '狀態'));
        foreach ($laws as $k => $v) {
            fputcsv($fp, $v);
        }
        fclose($fp);

        $law_categories = self::getLawCategories();
        $rows = array($category, $category2, $title);
        $law_categories[implode(',', array($category, $category2, $law_id))] = array(
            $category, $category2, $status, $law_id, $title
        );
        ksort($law_categories);

        $fp = fopen('laws-category.csv', 'w');
        fputcsv($fp, array('主分類', '次分類', '狀態', '代碼', '法條名稱'));
        foreach ($law_categories as $rows) {
            fputcsv($fp, $rows);
        }
        fclose($fp);
   }

    public function crawlLatestLaws()
    {
        $url = 'https://lis.ly.gov.tw/lglawc/lglawkm';
        $content = $this->http($url);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        $law_categories = self::getLawCategories();

        while (true) {
            foreach ($doc->getElementsByTagName('a') as $a_dom) {
                if (false === strpos($a_dom->getAttribute('href'), 'lglawc/lawsingle')) {
                    continue;
                }

                $match_laws = array();
                $url = $a_dom->getAttribute('href');
                $title = trim($a_dom->nodeValue);
                foreach ($law_categories as $law_category) {
                    if ($law_category[4] == $title) {
                        $match_laws[$law_category[3]] = $law_category;
                    }
                }

                $match_laws = array_values($match_laws);
                if (count($match_laws) != 1) { // 如果有多筆符合，就進去找法條 ID
                    $tmp_url = 'https://lis.ly.gov.tw' . $a_dom->getAttribute('href');
                    $content = $this->http($tmp_url);
                    $tmp_doc = new DOMDocument;
                    @$tmp_doc->loadHTML($content);

                    // 先取出法條 ID
                    $law_id = null;
                    foreach ($tmp_doc->getElementsByTagName('td') as $td_dom) {
                        if ($td_dom->getAttribute('class') == 'law_NA' and preg_match('#\((\d+)\)$#', trim($td_dom->nodeValue), $matches)) {
                            $law_id = $matches[1];
                            break;
                        }
                    }
                    $match_laws = array_values(array_filter($match_laws, function($r) use ($law_id) {
                        return $r[3] == $law_id;
                    }));
                }

                list($category, $category2, $type) = $match_laws[0];

                $this->crawlLaw($category, $category2, $type, $title, $url);
            }
            if (!$doc = self::getNextPageDoc($doc)) {
                break;
            }
        }
    }

    public function fetchRelate($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        file_put_contents('tmp', $content);
        foreach ($doc->getElementsByTagName('img') as $img_dom) {
            if ($img_dom->getAttribute('src') == '/lglaw/images/relate.png') {
                $href = $img_dom->parentNode->getAttribute('href');
                if (!preg_match('#/lglawc/lawsingle\?(.*)#', $href, $matches)) {
                    throw new Exception("relate 的網址不是 lawsingle");
                }
                $id = $matches[1];
                $url = 'https://lis.ly.gov.tw' . $href;
                if (file_exists(__DIR__ . "/laws/relate/{$id}.html")) {
                    continue;
                }
                $content = $this->http($url);
                file_put_contents(__DIR__ . "/laws/relate/{$id}.html", $content);
            }
        }
    }

    public function main()
    {
        if (!file_exists(__DIR__ . '/laws')) {
            mkdir(__DIR__ . '/laws');
            mkdir(__DIR__ . '/laws/relate');
        }

        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);

        // 先抓分類列表
        $categories = $this->crawlCategory();

        // 去看有沒有分類的數量有變，表示可能有新增法條
        $this->crawlLawList($categories);

        // 再回頭去最新公布法律抓新公布法
        $this->crawlLatestLaws();
    }
}

$c = new Crawler;
$c->main();
