<?php

date_default_timezone_set('Asia/Taipei');

$fp = fopen(__DIR__ . '/../laws.csv', 'r');
fgetcsv($fp);
$users = array();
while ($rows = fgetcsv($fp)) {
    list($id, $name, $type) = $rows;
    if (!$obj = json_decode(file_get_contents(__DIR__ . "/../law_cache/{$id}.json"))) {
        error_log($id);
        continue;
    }
    if (!property_exists($obj, 'law_history')) {
        continue;
    }
    foreach ($obj->law_history as $history) {
        if (property_exists($history, '主提案')) {
            $users[$history->{'主提案'}] = true;
        }
    }
}
fclose($fp);
ksort($users);

$output = fopen('user.csv', 'w');
fputcsv($output, array('提案者'));
foreach (array_keys($users) as $user) {
    echo $user . ' ' . substr(md5($user), 0, 16) . "\n";
    fputcsv($output, array($user));
}
fclose($output);
