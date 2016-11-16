<?php
/*
================================================================================
/classes/vk.php
--------------------------------------------------------------------------------
Основной класс для работы с VK.Api
--------------------------------------------------------------------------------
https://github.com/z-aec/php-vk
================================================================================
*/
class VKException extends Exception {}
class VK{

    private $api_version = "5.52";

    //Язык для возвращаемых результатов API (русский)
    private $lang = "ru";

    //Актуальные ссылки для запросов к VK.API
    const OAUTH_URL = "https://oauth.vk.com/authorize";
    const API_URL = "https://api.vk.com/method/";
    const BLANK_URL = "https://oauth.vk.com/blank.html";
    const ACCESS_TOKEN_URL = "https://oauth.vk.com/access_token";

    //Методы загрузки и сохранения на сервер вк
    const UPLOAD_METHODS = [
        "photo.album" => [
            "upload"    => "photos.getUploadServer",
            "save"      => "photos.save",
            "fields"    => ["file1", "file2", "file3", "file4", "file5"],
        ],
        "photo.wall"  => [
            "upload"    => "photos.getWallUploadServer",
            "save"      => "photos.saveWallPhoto",
            "fields"    => ["photo"],
        ],
    ];

    //Лимиты ВК
    const EXECUTE_REQUESTS_LIMIT = 25; //Количество обращений к api в execute
    const EXECUTE_CODE_MAX_LEN = 65536; //Максимальная длина поля code

    //Время на соединение с сервером VK, секунды
    const REQUEST_TIMEOUT = 15;

    //Количество попыток получить результат в случае превышения лимита
    const RETRY_COUNTER = 5;

    //Задержка выполнения запроса при переполучении результата, секунд
    const RETRY_DELAY = 0.5;

    //Строковые константы для возвращаемых значений
    const RESPONSE = "response";
    const ERROR = "error";
    const RETRY = "retry";
    const EXECUTE_ERRORS = "execute_errors";

    //Массив с ключами доступа
    private $access_tokens = [
        'anonymous' => "",
        'user' => "",
    ];

    //Параметры приложения
    private $app_id = 0;
    private $secret_key = "";

    //Переменная соединения
    private $ch;

    //Переменные для буферизации запросов
    private $requests_buffer = [];
    private $requests_buffer_pointer;
    private $requests_buffer_counter;

    //Количество запросов
    private $requests = 0;

    private function request($url, $params = [], $method = "POST"){
        //Выполняет соединение с сервером ВК и получает ответ.
        curl_setopt_array($this->ch, [
            CURLOPT_USERAGENT   => 'php-vk (https://github.com/z-aec/php-vk)',
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_POST            => ($method == 'POST'),
            CURLOPT_POSTFIELDS      => $params,
            CURLOPT_URL             => $url,
            CURLOPT_TIMEOUT         => self::REQUEST_TIMEOUT,
        ]);
        $this->requests++;
        return curl_exec($this->ch);
    }

    private function uploadRequest($url, $params = []){
        curl_setopt_array($this->ch, [
            CURLOPT_USERAGENT  => 'php-vk (https://github.com/z-aec/php-vk)',
            CURLOPT_HTTPHEADER      => ["Content-type: multipart/form-data"],
            CURLOPT_SAFE_UPLOAD     => false,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_POST            => 1,
            CURLOPT_POSTFIELDS      => $params,
            CURLOPT_URL             => $url
        ]);
        return curl_exec($this->ch);
    }

    private function pushRequestsBuffer($method, $params, $token){
        //Буферизует запрос с целью оптимизации количества запросов
        if(!isset($this->requests_buffer[$token])){
            $this->requests_buffer[$token] = [];
            $this->requests_buffer_pointer[$token] = 0;
            $this->requests_buffer_counter[$token] = 0;
        }
        if(($this->requests_buffer_counter[$token]
            - $this->requests_buffer_pointer[$token])
            == self::EXECUTE_REQUESTS_LIMIT)
        {
            $this->executeBuffer($token);
        }
        $this->requests_buffer[$token][] = [
            "method" => $method,
            "params" => $params,
        ];
        $this->requests_buffer_counter[$token]++;
        return $this->requests_buffer_counter[$token] - 1;
    }

    private function executeBuffer($token, $depth = self::RETRY_COUNTER){
        //Выполняет буферизованные запросы к Api

        /*
        Для оптимизации используется метод execute (https://vk.com/dev/execute),
        позволяющий одновременно уместить self::EXECUTE_REQUESTS_LIMIT запросов
        в один.
        */
        $var = "var ";
        $arr = "";
        $code = "return [";
        if($token === "anonymous") return $this;
        $offset = $this->requests_buffer_pointer[$token];
        $count = $this->requests_buffer_counter[$token];
        if($offset == $count) return $this;
        for($i = $offset; $i < $count; $i++){
            $var_add = "v" . $i . ",";
            $params = "{";
            foreach ($this->requests_buffer[$token][$i]['params'] as $key => $value) {
                if(!isset($value['type'])){
                    $params .= $key . ":" . json_encode($value) . ",";
                }else if($value['type'] === "var"){
                    $params .= $key . ":" . $value['content'] . ",";
                }
            }
            $params = substr($params, 0, -1) . "}";
            $arr_add = "v" . $i . "=" . "API."
                . $this->requests_buffer[$token][$i]['method']
                . "("
                . $params
                . ");";
            $code_add = "v" . $i . ",";
            if(strlen($var . $var_add . $arr. $arr_add . $code . $code_add) <= self::EXECUTE_CODE_MAX_LEN - 2){
                $var .= $var_add;
                $arr .= $arr_add;
                $code .= $code_add;
            }else{
                $count = $i;
                break;
            }
        }
        $code = substr($code, 0, -1) . "];";
        $var = substr($var, 0, -1) . ";";
        $code = $var. $arr . $code;

        $result = $this->apiQuery("execute", ["code" => $code], $token);

        if(isset($result['error']['error_code'])
            && $result['error']['error_code'] == 6 && $depth > 0)
        {
            usleep(self::RETRY_DELAY * 1000000);
            return $this->executeBuffer($token, $depth - 1);
        }
        $error_pointer = 0;
        if($result[self::RESPONSE]){
            foreach($result[self::RESPONSE] as $key => $item){
                $this->requests_buffer[$token][$offset + $key][self::RESPONSE]
                    = $item;
                if(!$item
                    && isset($result[self::EXECUTE_ERRORS][$error_pointer]))
                {
                    $this->requests_buffer[$token][$offset + $key][self::ERROR]
                        = $result[self::EXECUTE_ERRORS][$error_pointer];
                    $error_pointer++;
                }
            }
        }
        $this->requests_buffer_pointer[$token] = $count;
        if($count < $this->requests_buffer_counter[$token]){
            return $this->executeBuffer($token, $depth);
        }
        return $this;
    }

    private function getResult($method, $params, $token, $id){
        //Получает результат из буфера для конкретного запроса
        if(!isset($this->requests_buffer[$token][$id][self::RESPONSE])){
            $this->executeBuffer($token);
        }
        return $this->requests_buffer[$token][$id];
    }

    public function __construct($params = null){
        //Инициализация класса
        if(isset($params['app_id'])) {
            $this->app_id = $params['app_id'];
        }
        if(isset($params['secret_key'])){
            $this->secret_key = $params['secret_key'];
        }
        if(isset($params['v'])){
            $this->api_version = $params['v'];
        }

        $this->ch = curl_init();
    }

    public function __destruct(){
        //Уничтожение класса. Довыполняет все отложенные запросы.
        foreach($this->access_tokens as $key => $token){
            $this->executeBuffer($key);
        }
    }

    public function getAuthUrl($scope = [], $redirect_uri = self::BLANK_URL,
        $response_type = "token", $params = [])
    {
        //Получает ссылку для авторизации приложения
        $client_id = null;
        if(!isset($params['client_id'])){
            $client_id = $this->app_id;
        }else{
            $client_id = $params['client_id'];
            unset($params['client_id']);
        }
        if(is_array($scope)){
            $scope = implode(",", $scope);
        }
        $result = self::OAUTH_URL
            . "?client_id="     . $client_id
            . "&redirect_uri="  . $redirect_uri
            . "&scope="         . $scope
            . "&response_type=" . $response_type
            ;
        foreach ($params as $key => $value){
            $result .= "&" . $key . "=" . $value;
        }

        return $result;
    }

    public function setAccessToken($token, $key = "user"){
        //Сохраняет ключ доступа в массив
        $this->access_tokens[$key] = $token;
        return $this;
    }

    public function getAccessToken($code, $redirect_uri = self::BLANK_URL){
        $url = self::ACCESS_TOKEN_URL;
        $params = [
            "client_id" => $this->app_id,
            "client_secret" => $this->secret_key,
            "redirect_uri" => $redirect_uri,
            "code" => $code,
        ];
        $result = json_decode($this->request($url, $params), true);
        return $result;
    }

    public function apiQuery($method, $params = [], $token = "anonymous"){
        //Выполняет прямой запрос к VK.API
        $url = self::API_URL . $method . ".json";
        if(!isset($params['v'])){
            $params['v'] = $this->api_version;
        }
        if(!isset($params['lang'])){
            $params['lang'] = $this->lang;
        }
        if(!isset($params['access_token'])
            && isset($this->access_tokens[$token]))
        {
            $params['access_token'] = $this->access_tokens[$token];
        }
        $result = $this->request($url, $params);
        if(!$result){
            return false;
        }
        $result = json_decode($result, true);
        return $result;
    }

    public function query($method, $params = [], $token = "user"){
        //Выполняет по возможности буферизованный запрос к VK.API

        /*
        Используются "ленивые" вычисления, запрос (возможно, оптимизированный),
        выполняется при первом обращении к переменной-функции результата.
        */
        $response = null;
        $self = $this;
        if($this->access_tokens[$token] == ""
            || explode(".", $method)[0] == "execute")
        {
            return function($result = 0, $retry = false)
            use (&$response, $self, $method, $params, $token){
                if($result === self::RETRY){
                    $result = 0;
                    $retry = true;
                }
                if(!is_array($response) || $retry){
                    $response = $self->apiQuery($method, $params, $token);
                }
                if($result){
                    return $response[$result];
                }
                return isset($response[self::ERROR])
                    ? $response[self::ERROR]
                    : $response[self::RESPONSE];
            };
        }
        $response = $this->pushRequestsBuffer($method, $params, $token);
        return function($result = 0, $retry = false)
        use (&$response, $self, $method, $params, $token){
            if($result === self::RETRY){
                $result = 0;
                $retry = true;
            }
            if($result === "field"){
                if(!is_array($response)){
                    return function($keys) use ($response){
                        $str = "v" . $response;
                        foreach ($keys as $value) {
                            if(is_integer($value)) 
                                $str .= "[" . $value . "]";
                            else if($value == "@")
                                $str .= "@";
                            else
                                $str .= "." . $value;
                        }
                        return ["type" => "var", "content" => $str];
                    };
                }else{
                    return function($keys) use ($response){
                        $return = $response['response'];
                        $prev_at = false;
                        foreach ($keys as $value) {
                            if($prev_at){
                                $prev_at = false;
                                foreach ($return as $key => $val) {
                                    $return[$key] = $val[$value];
                                }
                                continue;
                            }
                            if($value !== "@"){
                                $return = $return[$value];
                            }else{
                                $prev_at = true;
                            }
                        }
                        return $return;
                    };
                }
            }
            if(!is_array($response) || $retry){
                if($retry){
                    $response = $self->pushRequestsBuffer($method, $params,
                        $token);
                }
                $response = $self->getResult($method, $params, $token,
                    $response);
            }
            if($result){
                return $response[$result];
            }
            return isset($response[self::ERROR])
                ? $response[self::ERROR]
                : $response[self::RESPONSE];
        };
    }

    public function getRequestsCount(){
        //Выдаёт общее количество запросов
        return $this->requests;
    }

    public function setArrayKey(&$array, $key = "id"){
        $result = [];
        foreach($array as $value){
            $k = $value[$key];
            $result[$k] = $value;
        }
        $array = $result;
        return $result;
    }

    public function upload($method, $url, $params = [], $token = "user"){
        if(is_array($url)){
            $server = $this->apiQuery(self::UPLOAD_METHODS[$method]['upload'],
                $params, $token);
            if(!$server["response"]["upload_url"]){
                return [function($_ = 0, $__ = 0) use ($server){
                    return isset($server['error']) ? $server['error'] : $server;
                }];
            }
            $result = [];
            $ptr = 0;
            $request_params = [];
            foreach($url as $key => $link){
                $request_params[self::UPLOAD_METHODS[$method]['fields'][$ptr]]
                    = '@' . realpath($link);
                $ptr++;
                //var_dump(self::UPLOAD_METHODS[$method]['fields'][$ptr]);
                if(!self::UPLOAD_METHODS[$method]['fields'][$ptr]
                    || !isset($url[$key + 1]))
                {
                    $upload = json_decode($this->uploadRequest(
                        $server["response"]["upload_url"], $request_params
                    ), true);

                    $p = array_merge($params, $upload);
                    $result[] = $this->query(
                        self::UPLOAD_METHODS[$method]['save'],
                        $p, $token
                    );
                    $ptr = 0;
                    $request_params = [];
                }
            }
            return $result;
        }else{
            return $this->upload($method, [$url], $params, $token)[0];
        }
    }
}
?>
