<?php

// 現讀入有哪些提案者列表
$fp = fopen('user.csv', 'r');
fgetcsv($fp);
while ($rows = fgetcsv($fp)) {
    $users[$rows[0]] = true;
}
fclose($fp);

// 再把已經有照片的刪掉
$fp = fopen('images.csv', 'r');
fgetcsv($fp);
while ($rows = fgetcsv($fp)) {
    list($name, $url) = $rows;
    if (array_key_Exists($name, $users)) {
        unset($users[$name]);
    }
}
fclose($fp);

// 從立委資料中找出有照片的
// wget 'http://data.ly.gov.tw/odw/usageFile.action?id=16&type=CSV&fname=16_CSV.csv' -O ly-data.csv
$fp = fopen('ly-data.csv', 'r');
$output = fopen('images.csv', 'a');
$columns = fgetcsv($fp);
while ($rows = fgetcsv($fp)) {
    $values = array_combine($columns, $rows);
    if (array_key_exists($values['name'], $users) and $values['picUrl']) {
        fputcsv($output, array($values['name'], $values['picUrl']));
        unset($users[$values['name']]);
    }
}
fclose($output);

$fp = fopen('missing.csv', 'w');
fputcsv($fp, array('名稱'));
foreach (array_keys($users) as $user) {
    fputcsv($fp, array($user));
}
