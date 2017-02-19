<?php

date_default_timezone_set('Asia/Taipei');

$fp = fopen(__DIR__ . '/../laws.csv', 'r');
fgetcsv($fp);
$users = array();
while ($rows = fgetcsv($fp)) {
    list($id, $name, $type) = $rows;
    $obj = json_decode(file_get_contents(__DIR__ . "/../law_cache/{$id}.json"));
    if ($obj and property_exists($obj, 'law_history') and property_exists($obj->law_history[0], '主提案')) {
        $users[$obj->law_history[0]->{'主提案'}] = true;
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
