<?php

include(__DIR__ . '/config.php');

$rpc_call = function($method, $params = array()){
    error_log("rpc {$method}: " . json_encode($params));
    $params['password'] = getenv('GRAVATAR_PASSWORD');

    $url = 'https://secure.gravatar.com/xmlrpc?user=' . md5(getenv('GRAVATAR_MAIL'));
    $curl = curl_init($url);
    $request = xmlrpc_encode_request($method, $params);

    curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $content = curl_exec($curl);
    curl_close($curl);

    $ret = xmlrpc_decode($content);
    return $ret;
};

// 讀 user 進來，取得代碼
$fp = fopen('user.csv', 'r');
$users = array();
while ($rows = fgetcsv($fp)) {
    $users[substr(md5($rows[0]), 0, 16)] = $rows[0];
}
fclose($fp);

// 讀 images.csv 進來
$fp = fopen('images.csv', 'r');
fgetcsv($fp);
$images = array();
$saved_images = array();

while ($rows = fgetcsv($fp)) {
    list($name, $url) = $rows;
    $images[$name] = $url;
}
fclose($fp);

// 從 gravatar api ，找出已經傳好的圖檔 
$ret = $rpc_call('grav.addresses');
foreach ($ret as $email => $config) {
    list($md5, $host) = explode('@', $email);
    if (!array_key_exists($md5, $users)) {
        continue;
    }
    $user = $users[$md5];
    if (strpos($config['userimage_url'], '/.jpg') === false) {
        unset($images[$user]);
    }
    $saved_images[$user] = array(
        'image_url' => (strpos($config['userimage_url'], '/.jpg') !== false) ? null : $config['userimage_url'],
    );
}

error_log("upload images");
// 把還沒傳的傳上去
foreach ($images as $name => $url) {
    // 如果帳號還沒建立需要建起來
    $email = substr(md5($name), 0, 16) . '@ly.govapi.tw';
    if (!array_key_exists($name, $saved_images)) {
        // 送出新增 email
        error_log("confirming {$email}");

        $curl = curl_init('http://en.gravatar.com/emails/new');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_Setopt($curl, CURLOPT_COOKIE, 'gravatar=' . (getenv('GRAVATAR_COOKIE')));
        $content = curl_exec($curl);
        preg_match('#<input type="hidden" value="([^"]*)" name="auth"/>#', $content, $matches);
        $auth = $matches[1];
        curl_close($curl);

        $curl = curl_init('http://en.gravatar.com/emails/add');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_Setopt($curl, CURLOPT_COOKIE, 'gravatar=' . (getenv('GRAVATAR_COOKIE')));
        curl_Setopt($curl, CURLOPT_POSTFIELDS, 'auth=' . urlencode($auth) . '&email=' . urlencode($email) . '&commit=Add');
        curl_exec($curl);
        curl_close($curl);

        error_log("waiting {$email} confirm");
        // 等 10 秒看有沒有成功，沒成功就中斷 (另一端有一隻收信 API)
        $start = microtime(true);
        while (true) {
            if (microtime(true) - $start > 10) {
                throw new Exception("超過 10 秒都沒收到 email {$email} 已被確認");
            }
            sleep(1);
            $ret = $rpc_call('grav.addresses');
            if (array_key_exists($email, $ret)) {
                $saved_images[$name] = array(
                    'image_url' => null,
                );
                break;
            }
        }
        error_log("confirmed $email");
    }

    $userimage = $rpc_call('grav.saveUrl', array(
        'url' => $url,
        'rating' => 0,
    ));

    $rpc_call('grav.useUserimage', array(
        'userimage' => $userimage,
        'addresses' => array(substr(md5($name), 0, 16) . '@ly.govapi.tw'),
    ));
    $saved_images[$name] = array(
        'image_url' => 'http://en.gravatar.com/userimage/116975125/' . $userimage . '.png',
    );
}

$fp = fopen('save-images.csv', 'w');
fputcsv($fp, array('name', 'image_url'));
foreach ($saved_images as $name => $config) {
    fputcsv($fp, array($name, $config['image_url']));
}
