<?php

class Crawler
{
    protected $curl;

    protected function http($url)
    {
        curl_setopt($this->curl, CURLOPT_URL, $url);
        $content = curl_exec($this->curl);
        $info = curl_getinfo($this->curl);
        if ($info['http_code'] != 200) {
            throw new Exception("抓取 {$url} 失敗, code = {$info['http_code']}");
        }
        return $content;
    }

    public function crawlCategory()
    {
        error_log("抓取法案分類清單");

        $categories = array();

        $content = $this->http('http://lis.ly.gov.tw/lglawc/lglawkm');
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
            throw new Exception("從 http://lis.ly.gov.tw/lglawc/lglawkm 找不到分類瀏覽網址");
        }

        $content = $this->http('http://lis.ly.gov.tw' . $category_link);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        foreach ($doc->getElementsByTagName('div') as $div_dom) {
            if ($div_dom->getAttribute('class') == 'title') {
                $category = $div_dom->nodeValue;
                foreach ($div_dom->previousSibling->getElementsByTagName('a') as $a_dom) {
                    $href = $a_dom->getAttribute('href');
                    if (strpos($href, '/lglawc/lglawkm') === false) {
                        continue;
                    }
                    preg_match('#(.*)\(.*\)#', $a_dom->nodeValue, $matches);
                    $title = $matches[1];
                    $categories[] = array($category, $title, $href);
                }
            }
        }
        return $categories;
    }

    public function crawlLawList($categories)
    {
        foreach ($categories as $rows) {
            list($category, $category2, $url) = $rows;
            $laws = array();
            error_log("抓取法案 {$category} > {$category2} 分類法案");

            $url = 'http://lis.ly.gov.tw' . $url;
            $content = $this->http($url);
            $doc = new DOMDocument;
            @$doc->loadHTML($content);

            // 先把廢止法連結拉出來備用
            $deprecate_link = null;
            foreach ($doc->getElementsByTagName('a') as $a_dom) {
                if (strpos($a_dom->nodeValue, '廢止法') === 0) {
                    $deprecate_link = $a_dom->getAttribute('href');
                    break;
                }
            }

            // 先爬現行法
            $page = 1;
            while (true) {
                foreach ($doc->getElementsByTagName('a') as $a_dom) {
                    if (strpos($a_dom->getAttribute('href'), '/lglawc/lawsingle') === 0) {
                        $this->crawlLaw($category, $category2, "現行", $a_dom->nodeValue, $a_dom->getAttribute('href'));
                    }
                }

                // 看看有沒有下一頁
                $has_next_page = false;
                $page ++;
                foreach ($doc->getElementsByTagName('a') as $a_dom) {
                    if ($a_dom->getAttribute('class') == 'linkpage' and trim($a_dom->nodeValue, html_entity_decode('&nbsp;')) == $page) {
                        $has_next_page = true;
                        $url = 'http://lis.ly.gov.tw' . $a_dom->getAttribute('href');
                        $content = $this->http($url);
                        $doc = new DOMDocument;
                        @$doc->loadHTML($content);
                        break;
                    }
                }

                if (!$has_next_page) {
                    break;
                }
            }

            // 再爬廢止法
            if (is_null($deprecate_link)) {
                continue;
            }
            $url = 'http://lis.ly.gov.tw' . $deprecate_link;
            $content = $this->http($url);
            $doc = new DOMDocument;
            @$doc->loadHTML($content);
            $page = 1;
            while (true) {
                foreach ($doc->getElementsByTagName('a') as $a_dom) {
                    if (strpos($a_dom->getAttribute('href'), '/lglawc/lawsingle') === 0) {
                        $this->crawlLaw($category, $category2, "廢止", $a_dom->nodeValue, $a_dom->getAttribute('href'));
                    }
                }

                // 看看有沒有下一頁
                $has_next_page = false;
                $page ++;
                foreach ($doc->getElementsByTagName('a') as $a_dom) {
                    if ($a_dom->getAttribute('class') == 'linkpage' and trim($a_dom->nodeValue, html_entity_decode('&nbsp;')) == $page) {
                        $has_next_page = true;
                        $url = 'http://lis.ly.gov.tw' . $a_dom->getAttribute('href');
                        $content = $this->http($url);
                        $doc = new DOMDocument;
                        @$doc->loadHTML($content);
                        break;
                    }
                }

                if (!$has_next_page) {
                    break;
                }
            }
        }
    }

    public function crawlLaw($category, $category2, $status, $title, $url)
    {
        if (file_exists(__DIR__ . "/laws/{$title}")) {
            return;
        }

        error_log("抓取條文 {$category} > {$category2} > {$title} 資料");
        if (file_exists("tmp")) {
            system("rm -rf tmp");
        }
        mkdir("tmp");

        $url = 'http://lis.ly.gov.tw' . $url;
        $content = $this->http($url);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        $version_fp = fopen("tmp/version.csv", "w");

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
            $url = 'http://lis.ly.gov.tw' . $law_url;
            $content = $this->http($url);
            file_put_contents("tmp/{$versions[0]}.html", $content);

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
                        $url = 'http://lis.ly.gov.tw' . $img_dom->parentNode->getAttribute('href');
                        $content = $this->http($url);
                        file_put_contents("tmp/{$versions[0]}-{$name}.html", $content);

                        $types[] = $name;
                    }
                }
            }
            fputcsv($version_fp, array(
                implode(';', $versions),
                implode(';', $types),
            ));
        }
        fclose($version_fp);
        rename("tmp", __DIR__ . "/laws/{$title}");
        file_put_contents(__DIR__ . "/laws.csv", implode(",", array(
            $category, $category2, $status, $title,
        )) . "\n", FILE_APPEND);
    }

    public function main()
    {
        if (!file_exists(__DIR__ . '/laws')) {
            mkdir(__DIR__ . '/laws');
        }

        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);

        $categories = $this->crawlCategory();
        $this->crawlLawList($categories);
    }
}

$c = new Crawler;
$c->main();
