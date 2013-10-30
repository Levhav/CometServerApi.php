<?php

/**
 *  Comet Server PHP Библиотека
 *  Библиотека предоставляет простое API для работы с Comet-Server.ru
 *
 *  Copyright 2013, Trapenok Victor. Licensed under the MIT license: http://www.opensource.org/licenses/mit-license.php
*/

define('NO_ERROR', 0);
define('ERROR_USER_OFLINE', -5);
define('ERROR_USER_OFLINE_AND_OVERFLOW_QUEUE', -10);
define('ERROR_USER_OFLINE_MORE_MAX_OFLINE_TIME', -11);

define('ERROR_USER_CONECTION', -6);

define('ERROR_UNDEFINED_EVENT', -7);
define('ERROR_UNDEFINED_SERVER_EVENT', -1);


/**
 * Обращение с номером больше чем размер индекса подключёных пользователей
 */
define('ERROR_MORE_MAX_USER_ID', -8);

/**
 * Обращение с номером больше чем максимальное количество подключёных пользователей
 */
define('ERROR_MORE_MAX_CONECTIONS', -9);

/**
 *  Comet Server PHP Библиотека
 *  Библиотека предоставляет простое API для работы с Comet-Server.ru
 *
 *  $comet = CometServerApi::getInstance();
 *  $comet->authorization(1, "0000000000000000000000000000000000000000000000000000000000000000");
 *  $comet->send_event('my_event', Array("data" => "testing") );
 *  $comet->send_event('my_event', "моё сообщение" );
 *
 *  Copyright 2013, Trapenok Victor. Licensed under the MIT license: http://www.opensource.org/licenses/mit-license.php
*/
class CometServerApi
{
   static $version=1.12;
   static $major_version=1;
   static $minor_version=32;

   protected $server = "comet-server.ru";
   protected $port = 808;
   protected $timeOut = 1;

   protected $authorization = false;
   protected $handle = false;

   protected $dev_id = false;
   protected $dev_key = false;

   protected static $_instance;


   protected static $ADD_USER_HASH = 1;
   protected static $SEND_BY_USER_ID = 3;
   protected static $SEND_GET_LAST_ONLINE_TIME = 5;
   protected static $SEND_EVENT = 6;


   /**
    * Конструетор оставлен публичным на тот случай если вам реально понадобится использовать два соединения с комет сервером единовременно но с разными $dev_id и $dev_key
    * Во всех остальных случаях используйте клас как singleton, тоесть вызывая CometServerApi::getInstance()
    *
    * @param int $dev_id Идентификатор разработчика
    * @param string $dev_key Секретный ключ разработчика
    */
   public function __construct($dev_id = false, $dev_key = false)
   {
       if($dev_id !== false) $this->dev_id = $dev_id;
       if($dev_key !== false) $this->dev_key = $dev_key;
   }

   /**
    * @return CometServerApi
    */
   public static function getInstance()
   {
        if (null === self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

   /**
    * Позволяет указать $dev_id и $dev_key, необходимо вызвать один раз перед использованием.
    *
    * Данные для авторизации будут отправлены вместе с первым запросом  к комет серверу.
    * Эта функция отдельного запроса не зоздаёт
    *
    * @param int $dev_id Идентификатор разработчика
    * @param string $dev_key Секретный ключ разработчика
    * @return \CometServerApi
    */
   public function authorization($dev_id, $dev_key)
   {
       $this->dev_id = $dev_id;
       $this->dev_key = $dev_key;
       return $this;
   }

   /**
    * Выполняет запросы
    * @todo Можно перевести на систему обмена без закрытия соединения после каждого запроса
    * @param string $msg
    * @return array
    */
   private function send($msg, $isRetry = false)
   {
       if($this->dev_id === false || $this->dev_key === false)
       {
           return Array("error" => 1, "info" => "Не установлен dev_id или dev_key, перед использованием следует вызвать функцию authorization", "conection" => 0, "event" => ERROR_UNDEFINED_EVENT);
       }

       if(!$this->handle)
       {
            if($this->timeOut)
            {
                $this->handle = @fsockopen("d".$this->dev_id.".app.".$this->server, $this->port,$e1,$e2,$this->timeOut);
            }
            else
            {
                $this->handle = @fsockopen($this->server, $this->port);
            }
       }

       if($this->handle)
       {
           if(!$this->authorization)
           {
               $msg = "A:::".$this->dev_id.";".self::$major_version.";".self::$minor_version.";".$this->dev_key.";".$msg;
               $this->authorization = true;
           }

	   // echo  $msg;
           if( @fputs($this->handle, $msg, strlen($msg) ) === false)
           {
               $this->handle = false;
               if($isRetry) return $this->send($msg, true);
           }

           $tmp = fgets($this->handle);
           // echo  "[".$tmp."]\n" ;
           return json_decode($tmp,true);
       }
       return false;
   }

   public function add_user_hash($user_id, $hash = false)
   {
      if($hash === false)
      {
          $hash = session_id();
      }

      return $this->send(self::$ADD_USER_HASH.";".$user_id.";".session_id()."");
   }

    /**
     * Отправка сообщения списку пользователей
     * @param array $user_id_array Масив с идентификаторами получателей
     * @param array $msg Структура данных которая будет отправлена, должна содержать поле name с именем адресата (chat, и др.)
     * @return array
     *
     * Перед отправкой данные конвертируются в json а затем кодируются в base64
     * Структура данных которая будет отправлена принимающий js ожидает увидеть поле name - имя события
     */
   public function send_by_user_id($user_id_array,$msg)
   {
        if(!is_array($user_id_array))
        {
            if( (int)$user_id_array > 0)
            {
                $n = 1;
            }
            else
            {
                return false;
            }
        }
        else
        {
            $n = count($user_id_array);
            foreach ($user_id_array as &$value)
            {
                $value = (int)$value;
                if($value < 1)
                {
                    return false;
                }
            }
            $user_id_array = implode(";",$user_id_array);
        }

        if(!is_string($msg))
        {
            $msg = json_encode($msg);
        }

      $msg = base64_encode($msg)."";
      return $this->send(self::$SEND_BY_USER_ID.";".$n.";".$user_id_array.";".$msg);
   }

   /**
    * Отправка сообщения списку пользователей
    * @param type $user_id_array
    * @param type $event_name
    * @param type $msg
    */
   public function send_to_user($user_id_array, $event_name, $msg)
   {
       return $this->send_by_user_id($user_id_array,Array("data"=>$msg,"event_name"=>$event_name) );
   }

     /**
      * Определяет количество секунд прошедшее с момента последнего прибывания пользователя online
      * Пример ответа:
      * {"conection":1,"event":5,"error":0,"answer":"0"}
      *
      * Если answer = 0 то человек online
      *
      * @param int $user_id
      * @return array
      */
   public function get_last_online_time($user_id_array)
   {
        if(!is_array($user_id_array))
        {
            $n = 1;
        }
        else
        {
            $n = count($user_id_array);
            $user_id_array = implode(";",$user_id_array);
        }
      return $this->send(self::$SEND_GET_LAST_ONLINE_TIME.";".$n.";".$user_id_array.";");
   }

    /**
     * Отправляет произвольное сообщение серверу
     * @param int $event_id
     * @param string $msg
     * @param bool $isCoding если true то сообщение перед отправкой будет закодировано в base64
     * @return array
     */
   public function send_event($event,$msg, $isCoding = false)
   {
      if($isCoding == false)
      {
      	  if(!is_string($msg))
          {
          	  $msg = json_encode($msg);
          }
          $msg = base64_encode($msg);
      }

      $n = 1;
      if( is_array($event) )
      {
          $answer = Array();
          foreach ($event as $key => $value)
          {
              $answer[] = $this->send(self::$SEND_EVENT.";".$value.";".$msg);
          }

          return $answer;
      }

      return $this->send(self::$SEND_EVENT.";".$event.";".$msg);
   }

}
?>
