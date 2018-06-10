<?php
/**
 * 获取权限，简单封装常用函数
 *
 * @author   fooleap <fooleap@gmail.com>
 * @version  2018-06-04 23:23:17
 * @link     https://github.com/fooleap/disqus-php-api
 *
 */
require_once('config.php');
require_once('jwt.php');
require_once('emoji.php');

error_reporting(E_ERROR | E_PARSE);
header('Content-type:text/json');
header('Access-Control-Allow-Credentials: true');
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';  
$ipRegex = '((2[0-4]|1\d|[1-9])?\d|25[0-5])(\.(?1)){3}';
function domain($url){
    preg_match('/[a-z0-9\-]{1,63}\.[a-z\.]{2,6}$/', parse_url($url, PHP_URL_HOST), $_domain_tld);
    return $_domain_tld[0];
}
if(preg_match('(localhost|'.$ipRegex.'|'.domain(DISQUS_WEBSITE).')', $origin)){
    header('Access-Control-Allow-Origin: '.$origin);
}

$jwt = new JWT();
$emoji = new Emoji();

$approved = DISQUS_APPROVED == 1 ? 'approved' : null;
$url = parse_url(DISQUS_WEBSITE);
$website = $url['scheme'].'://'.$url['host'];

$user = $_COOKIE['access_token'];

// 数据库连接
$db_conn = db_connect();

if ( isset($user) ){

    $userData = $jwt -> decode($user, DISQUS_PASSWORD);

    if( $userData ){

        $refresh_token = $userData['refresh_token'];
        $access_token = $userData['access_token'];

        if( $userData['exp'] < $_SERVER['REQUEST_TIME'] + 3600 * 20 * 24){

            $authorize = 'refresh_token';
            $fields = (object) array(
                'grant_type' => urlencode($authorize),
                'client_id' => urlencode(PUBLIC_KEY),
                'client_secret' => urlencode(SECRET_KEY),
                'refresh_token' => urlencode($refresh_token)
            );

            getAccessToken($fields);
        }

    }
}

function adminLogin(){

    $fields = (object) array(
        'username' => DISQUS_EMAIL,
        'password' => DISQUS_PASSWORD
    );

    $fields_string = fields_format($fields);

    $options = array(
        CURLOPT_URL => 'https://import.disqus.com/login/',
        CURLOPT_HEADER => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST => count($fields),
        CURLOPT_POSTFIELDS => $fields_string
    );

    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $result = curl_exec($curl);
    $errno = curl_errno($curl);

    if ($errno == 60 || $errno == 77) {
        curl_setopt($curl, CURLOPT_CAINFO, dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cacert.pem');
        $data = curl_exec($curl);
    }

    curl_close($curl);
    preg_match('/^Set-Cookie:\s+(session.*)/mi', $result, $matches);
    $cookieArr =  explode('; ',$matches[1]);
    $cookie = (object) array();

    foreach( $cookieArr as $value){

        if( strpos($value,'=') !== false){
            list($key, $val) = explode('=', $value);
            $cookie -> $key = $val;
        }

    }

    db_update('cookie', $cookie);
}

// 鉴权
function getAccessToken($fields){
    global $access_token, $jwt;

    extract($_POST);
    $url = 'https://disqus.com/api/oauth/2.0/access_token/?';

    $fields_string = fields_format($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, count($fields));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $data = curl_exec($ch);
    curl_close($ch);

    // 用户授权数据
    $auth_results = json_decode($data);

    // 换算过期时间
    $expires = $_SERVER['REQUEST_TIME'] + $auth_results -> expires_in;

    // 重新获取授权码
    $access_token = $auth_results -> access_token;

    $payload = (array) $auth_results;
    $payload['iss'] = DISQUS_EMAIL;
    $payload['iat'] = $_SERVER['REQUEST_TIME'];
    $payload['exp'] = $expires;

    setcookie('access_token', $jwt -> encode($payload, DISQUS_PASSWORD), $expires, substr(__DIR__, strlen($_SERVER['DOCUMENT_ROOT'])), $_SERVER['HTTP_HOST'], false, true); 

    return $access_token;
}

function encodeURIComponent($str){
    $replacers = [
        '%21' => '!',
        '%2A' => '*',
        '%27' => "'",
        '%28' => '(',
        '%29' => ')'
    ];
    if (!is_string($str)) return $str;
    return strtr(rawurlencode($str), $replacers);
}

function fields_format($fields){
    foreach($fields as $key=>$value) { 
        if (is_array($value)) {
            foreach( $value as $item ){
                $fields_string .= encodeURIComponent($key).'='.encodeURIComponent($item).'&';
            }
        } else {
            $fields_string .= encodeURIComponent($key).'='.encodeURIComponent($value).'&';
        }
    }
    $fields_string = rtrim($fields_string, '&');
    return $fields_string;
}

function curl_get($url, $fields){

    $fields -> api_key = DISQUS_PUBKEY;
    $cookies = 'sessionid='.db_select('cookie', 'sessionid');

    $fields_string = fields_format($fields);

    $curl_url = 'https://disqus.com'.$url.$fields_string;

    $options = array(
        CURLOPT_URL => $curl_url,
        CURLOPT_HTTPHEADER => array('Host: disqus.com','Origin: https://disqus.com'),
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => 1 
    );

    $curl = curl_init();
    curl_setopt_array($curl, $options);

    if( isset($cookies)){
        curl_setopt($curl, CURLOPT_COOKIE, $cookies);
    }

    $data = curl_exec($curl);
    $errno = curl_errno($curl);
    if ($errno == 60 || $errno == 77) {
        curl_setopt($curl, CURLOPT_CAINFO, dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cacert.pem');
        $data = curl_exec($curl);
    }
    curl_close($curl);

    return json_decode($data);
}

function curl_post($url, $fields){

    global $access_token;

    if( isset($access_token) && strpos($url, 'threads/create') === false && strpos($url, 'media') === false ){

        $fields -> api_secret = SECRET_KEY;
        $fields -> access_token = $access_token;

    } else {

        $fields -> api_key = DISQUS_PUBKEY;
        $cookies = 'sessionid='.db_select('cookie', 'sessionid');
    }

    if( strpos($url, 'media') !== false ){

        $curl_url = 'https://uploads.services.disqus.com'.$url;
        $curl_host = 'uploads.services.disqus.com';

        $fields_string = $fields;

    } else {

        $curl_url = 'https://disqus.com'.$url;
        $curl_host = 'disqus.com';

        $fields_string = fields_format($fields);
    }

    $curl = curl_init();
    $options = array(
        CURLOPT_URL => $curl_url,
        CURLOPT_HTTPHEADER => array('Host: '.$curl_host,'Origin: https://disqus.com'),
        CURLOPT_HEADER => 0,
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_POST => count($fields),
        CURLOPT_POSTFIELDS => $fields_string
    );

    curl_setopt_array($curl, $options);

    if( isset($cookies)){
        curl_setopt($curl, CURLOPT_COOKIE, $cookies);
    }

    $data = curl_exec($curl);
    $errno = curl_errno($curl);
    if ($errno == 60 || $errno == 77) {
        curl_setopt($curl, CURLOPT_CAINFO, dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cacert.pem');
        $data = curl_exec($curl);
    }
    curl_close($curl);

    return json_decode($data);
}

function post_format( $post ){
    global $emoji;

    // 是否是管理员
    $isMod = ($post -> author -> username == DISQUS_USERNAME || $post -> author -> email == DISQUS_EMAIL ) && $post -> author -> isAnonymous == false ? true : false;

    // 访客指定 Gravatar 头像
    
    if( defined('GRAVATAR_DEFAULT') ){
        $avatar_default = GRAVATAR_DEFAULT;
    } else {
        $avatar_forum = db_select('forum', 'avatar');
        $avatar_default = strpos($avatar_forum, 'https') !== false ? $avatar_forum : 'https:'.$avatar_forum;
    }

    $avatar_url = GRAVATAR_CDN.md5($post -> author -> name).'?d='.$avatar_default.'&s=92&f=y';
    $post -> author -> avatar -> cache = $post -> author -> isAnonymous ? $avatar_url : $post -> author -> avatar -> cache;

    // 表情
    $post -> message = $emoji -> toImage($post -> message);

    // 链接
    $post -> author -> url = !!$post -> author -> url ? $post -> author -> url : $post -> author -> profileUrl;

    // 去除链接重定向
    $urlPat = '/<a.*?href="(.*?[disq\.us][disqus\.com].*?)".*?>(.*?)<\/a>/mi';
    preg_match_all($urlPat, $post -> message, $urlArr);    
    if( count($urlArr[0]) > 0 ){
        $linkArr = array();
        foreach ( $urlArr[1] as $item => $urlItem){
            if(preg_match('/^(http|https):\/\/disq\.us/i', $urlItem)){
                parse_str(parse_url($urlItem,PHP_URL_QUERY),$out);
                $linkArr[$item] = '<a href="'.join(':', explode(':',$out['url'],-1)).'" target="_blank" title="'.$urlArr[2][$item].'">'.$urlArr[2][$item].'</a>';
            } elseif ( strpos($urlItem, 'https://disqus.com/by/') !== false ){
                $linkArr[$item] = '<a href="'.$urlItem.'" target="_blank" title="'.$urlArr[2][$item].'">@'.$urlArr[2][$item].'</a>';
            } else {
                $linkArr[$item] = '<a href="'.$urlItem.'" target="_blank" title="'.$urlArr[2][$item].'">'.$urlArr[2][$item].'</a>';
            }
        }
        $post -> message = str_replace($urlArr[0],$linkArr,$post -> message);
    }

    // 去掉图片链接
    $imgpat = '/<a(.*?)href="(.*?(disquscdn.com|media.giphy.com).*?\.(jpg|gif|png))"(.*?)>(.*?)<\/a>/i';
    $post -> message = preg_replace($imgpat,'',$post -> message);

    $imgArr = array();
    foreach ( $post -> media as $key => $image ){
        
        $imgArr[$key] = (object) array(
            'thumbWidth' => $image -> thumbnailWidth,
            'thumbHeight' => $image -> thumbnailHeight,
            'provider' => $image -> providerName
        );

        if( $image -> url !== 'https://disqus.com' && $image -> url !== 'disqus.com' ){
            if( strpos($image -> url, 'giphy.gif') !== false ){
                $imgArr[$key] = '//a.disquscdn.com/get?url='.urlencode($image -> url).'&key=Hx_Z1IMzKElPuRPVmpnfsQ';
            } else {
                $imgArr[$key] = $image -> url;
            }
        }
    };
    $imgArr = array_reverse($imgArr);

    // 是否已删除
    if(!!$post -> isDeleted){
        $post -> message = '';
        $post -> author -> avatar -> cache =  db_select('forum', 'avatar');
        $post -> author -> username = '';
        $post -> author -> name = '';
        $post -> author -> url = '';
        $isMod = '';
    }

    $data = array( 
        'avatar' => $post -> author -> avatar -> cache,
        'isMod' => $isMod,
        'isDeleted' => $post -> isDeleted,
        'username' => $post -> author -> username,
        'createdAt' => $post -> createdAt.'+00:00',
        'id' => $post -> id,
        'media' => $imgArr,
        'message' => $post -> message,
        'name' => $post -> author -> name,
        'parent' => $post -> parent,
        'url' => $post -> author -> url
    );

    return $data;
}

// 连接数据库
function db_connect() {

    $conn = pg_connect(DATABASE_URL);

    // 是否存在 disqus 表
    $result = pg_query($conn, "SELECT CASE WHEN EXISTS( SELECT 1 FROM pg_tables WHERE tablename = 'disqus' ) THEN 1 ELSE 0 END");
    $isTableExists = pg_fetch_result($result, 0);

    // 若不存在，则新建 disqus 表
    if( !$isTableExists ) {
        pg_query($conn, "CREATE TABLE disqus(type VARCHAR(10) UNIQUE, data jsonb)");
        pg_query($conn, "INSERT INTO disqus(type, data) VALUES('forum', '{}')");
        pg_query($conn, "INSERT INTO disqus(type, data) VALUES('posts', '{}')");
        pg_query($conn, "INSERT INTO disqus(type, data) VALUES('cookie', '{}')");
    }

    return $conn;
}

// 获取数据
function db_select($type, $key = NULL){
    global $db_conn;

    if( $key ) {
        $query = sprintf("SELECT data->'%s' FROM disqus WHERE type = '%s'", $key, $type);
    } else {
        $query = sprintf("SELECT data FROM disqus WHERE type = '%s'", $type);
    }

    $result = pg_query($db_conn, $query);
    if( $result ) {
        return json_decode(pg_fetch_result($result, 0));
    } else {
        error_log(pg_result_error($result));
    }
}

// 更新数据
function db_update($type, $data, $key = NULL){
    global $db_conn;

    $data = json_encode($data);
    if( $key ) {
        $query = sprintf("UPDATE disqus SET data = jsonb_set(data, '{%s}', '%s') WHERE type = '%s'", $key, $data, $type);
    } else {
        $query = sprintf("UPDATE disqus SET data = '%s' WHERE type = '%s'", $data, $type);
    }

    $result = pg_query($db_conn, $query);
    if( !$result ) {
        error_log(pg_last_error($db_conn));
    }
}

// 删除数据
function db_remove($type, $key = NULL){
    global $db_conn;

    if( $key ) {
        $query = sprintf("UPDATE disqus SET data = data - '%s' WHERE type = '%s'", $key, $type);
    } else {
        $query = sprintf("UPDATE disqus SET data = '{}' WHERE type = '%s'", $type);
    }

    $result = pg_query($db_conn, $query);
    if( !$result ) {
        error_log(pg_last_error($db_conn));
    }
}

function getForumData(){

    $fields = (object) array(
        'forum' => DISQUS_SHORTNAME
    );
    $curl_url = '/api/3.0/forums/details.json?';
    $data = curl_get($curl_url, $fields);
    $modText = $data -> response -> moderatorBadgeText;
    $forum = array(
        'founder' => $data -> response -> founder,
        'name' => $data -> response -> name,
        'url' => $data -> response -> url,
        'avatar' => $data -> response -> avatar -> large -> cache,
        'moderatorBadgeText' =>  !!$modText ? $modText : 'Moderator',
        'expires' => time() + 3600*24
    );
    if( $data -> code == 0 ){
        db_update('forum', $forum);
    }
}

// 取得当前目录
function getCurrentDir (){

    $isSecure = false;
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
        $isSecure = true;
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
        $isSecure = true;
    }

    $protocol = $isSecure ? 'https://' : 'http://';

    return $protocol.$_SERVER['HTTP_HOST'].substr(__DIR__, strlen($_SERVER['DOCUMENT_ROOT']));

}

if( time() > strtotime(db_select('cookie', 'expires')) || !db_select('cookie')){
    adminLogin();
}

if( time() > db_select('forum', 'expires') || !db_select('forum')){
    getForumData();
}
