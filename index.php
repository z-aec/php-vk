<?php
require_once "./classes/vk.php";
require_once "./classes/response.php";

//сначала инициализируем приложение
//создайте своё приложение-сайт на vk.com/dev
//и укажите данные от него ниже
$vk = new VK([
    "app_id" => 0, //id вашего приложения
    "secret_key" => 'защищённый ключ',
]);

echo "<pre>";

$redirect = urlencode("http://" . $_SERVER['HTTP_HOST'] . "/php-vk/index.php");
echo "<a href='"
    . $vk->getAuthUrl(["wall", "offline", "photos"], $redirect, "code")
    . "'>App auth</a>"
    . "\n";

if(isset($_GET['code'])){
    $token = $vk->getAccessToken($_GET['code'], urldecode($redirect));
    if(isset($token['access_token'])){
        $vk->setAccessToken($token['access_token']);
    }
}
//пример самих запросов
$durov = $vk->query("users.get", ["user_ids" => 1]);
$durov_wall = $vk->query("wall.get", ['owner_id' => 1, "count" => 1]);
$api_wall = $vk->query("wall.get", ['owner_id' => -1, "count" => 1]);

var_dump($durov('response')[0]);
var_dump($durov_wall()['items']);
var_dump($api_wall());

echo "\n" . "API Requests: " . $vk->getRequestsCount();
echo "</pre>";
?>
