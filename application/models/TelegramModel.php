<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class TelegramModel extends CI_Model {

    function __construct() {
        parent::__construct();
        $this->db = $this->load->database('telegram', TRUE);
    }

    function apiRequestWebhook($method, $parameters) {
        if (!is_string($method)) {
            error_log("Method name must be a string\n");
            return false;
        }

        if (!$parameters) {
            $parameters = array();
        } else if (!is_array($parameters)) {
            error_log("Parameters must be an array\n");
            return false;
        }

        $parameters["method"] = $method;

        header("Content-Type: application/json");
        echo json_encode($parameters);
        return true;
    }

    function exec_curl_request($handle) {
        $response = curl_exec($handle);

        if ($response === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            error_log("Curl returned error $errno: $error\n");
            curl_close($handle);
            return false;
        }

        $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
        curl_close($handle);

        if ($http_code >= 500) {
            // do not wat to DDOS server if something goes wrong
            sleep(10);
            return false;
        } else if ($http_code != 200) {
            $response = json_decode($response, true);
            error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
            if ($http_code == 401) {
                throw new Exception('Invalid access token provided');
            }
            return false;
        } else {
            $response = json_decode($response, true);
            if (isset($response['description'])) {
                error_log("Request was successful: {$response['description']}\n");
            }
            $response = $response['result'];
        }

        return $response;
    }

    function apiRequest($method, $parameters) {
        if (!is_string($method)) {
            error_log("Method name must be a string\n");
            return false;
        }

        if (!$parameters) {
            $parameters = array();
        } else if (!is_array($parameters)) {
            error_log("Parameters must be an array\n");
            return false;
        }

        foreach ($parameters as $key => &$val) {
            // encoding to JSON array parameters, for example reply_markup
            if (!is_numeric($val) && !is_string($val)) {
                $val = json_encode($val);
            }
        }
        $url = API_URL.$method.'?'.http_build_query($parameters);

        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 60);

        return $this->exec_curl_request($handle);
    }

    function apiRequestJson($method, $parameters) {
        if (!is_string($method)) {
            error_log("Method name must be a string\n");
            return false;
        }

        if (!$parameters) {
            $parameters = array();
        } else if (!is_array($parameters)) {
            error_log("Parameters must be an array\n");
            return false;
        }

        $parameters["method"] = $method;

        $handle = curl_init(API_URL);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 60);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
        curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

        return $this->exec_curl_request($handle);
    }

    function updateUserPrev($user_id,$state){
        $this->db->query("UPDATE status SET prev='$state' WHERE user_id=$user_id");
    }
    function updateUserNext($user_id,$state){
        $this->db->query("UPDATE status SET next='$state' WHERE user_id=$user_id");
    }

    function getQuestion($trigger,$user_id){
        $status = $this->db->query("SELECT `prev`, `next` FROM `status` WHERE `user_id`='$user_id'")->row();
        
        $filter = "";
        if ($status->next != '')
            $filter .= " and `id` in ($status->next)";
        if ($status->prev != ''){
            $filter .= " and `prev` = '$status->prev'";
        }else{
            $filter .= " and `prev` = ''";
        }
        
        
        $query = "SELECT question.id as id, question.prev as prev, question.next as next, question.message as message, question.answer as answer FROM question WHERE `trigger`='$trigger' $filter";
        $return = $this->db->query($query)->row();
        if ($return != null) {
            return $return;
        }

        //next and trigger is not working, try to use prev and no trigger 
        $query = "select question.id as id, question.prev as prev, question.next as next, question.message as message, question.answer as answer from question join status on question.prev = status.prev where status.user_id = '$user_id' and question.trigger = ''";
        $return = $this->db->query($query)->row();
        if ($return != null) {
            return $return;
        }
    }

    function processMessage($update) {
        $this->saveRawUpdate($update);
        if($this->isUpdateExist($update)){
            return;
        }
        
        if($update['callback_query'] != null) {
            $message = $update['callback_query']["message"];
            $message_id = $message['message_id'];
            $chat_id = $message['chat']['id'];
            $user_id = $message['chat']['id'];//pake yg chat
            $text = $update['callback_query']['data'];
        }else{
            $message = $update["message"];
            $message_id = $message['message_id'];
            $chat_id = $message['chat']['id'];
            $user_id = $message['from']['id'];
            $text = $message['text'];
        }

        if(isset($message['entities']) && isset($message['entities']['type'])){
            //do nothing
        }elseif (isset($message['text'])) {
            // incoming text message
            $this->saveMessage($message);


            //dynamic question from db start here
            $question = $this->getQuestion($text,$user_id);
            if (strpos($text, "/reset") === 0) {
                $this->updateUserPrev($user_id,'');
                $this->updateUserNext($user_id,'');
                $sendMessage = array('chat_id' => $chat_id, "text" => "Reset success.");
                $this->apiRequest("sendMessage", $sendMessage);
                $this->saveBotMessage($message_id,$sendMessage);
            }elseif($question != null){
                $sendText = $question->message;
                $sendMessage = array('chat_id' => $chat_id, "text" =>$sendText);
                if($question->answer != null && $question->answer != ""){
                    $sendMessage["reply_markup"] = json_decode($question->answer,true);
                }
                $this->apiRequestJson("sendMessage", $sendMessage);
                $this->saveBotMessage($message_id,$sendMessage);

                $this->updateUserPrev($user_id,$question->id);// use id not prev, because it is the current state of user after finishing the question
                $this->updateUserNext($user_id,$question->next);
                if($question->next == '')
                $this->updateUserPrev($user_id,'');//reset prev if question next empty (last question no need to track previous one)
                // return;
            }else{
                $sendMessage = array('chat_id' => $chat_id, "text" => "There is invalid action. Please continue with the proper answer or try to /reset");
                $this->apiRequest("sendMessage", $sendMessage);
                $this->saveBotMessage($message_id,$sendMessage);
            }

            return;

            //test purpose
            $sendMessage = array('chat_id' => $chat_id, "text" => $question);
            $this->apiRequest("sendMessage", $sendMessage);
            $this->saveBotMessage($message_id,$sendMessage);

            // if (strpos($text, "/q1") === 0) {
            //     $sendText = "Test Q1";
            //     $sendMessage = array('chat_id' => $chat_id, "text" =>$sendText, 'reply_markup' => array(
            //         'inline_keyboard' => array(array(
            //             array('text' => 'OK', 'callback_data' => 'callback1'),
            //             array('text' => 'Not OK', 'callback_data' => 'callback2')
            //         )))
            //     );
            //     $this->apiRequestJson("sendMessage", $sendMessage);
            //     $this->saveBotMessage($message_id,$sendMessage);
            //     $this->updateUserStatus($user_id,"registration","");
            // }elseif (strpos($text, "/q2") === 0) {
            //     $sendText = "Test Q2";
            //     $sendMessage = array('chat_id' => $chat_id, "text" =>$sendText, 'reply_markup' => array(
            //         'keyboard' => array(array(array('text' => '/a1 Kuuy', 'callback_data' => 'callback_data1'))),
            //         'one_time_keyboard' => true,
            //         'resize_keyboard' => true)
            //     );
            //     $this->apiRequestJson("sendMessage", $sendMessage);
            //     $this->saveBotMessage($message_id,$sendMessage);
            //     $this->updateUserStatus($user_id,"registration","");
            // }elseif (strpos($text, "/q3") === 0) {
            //     $sendText = "Test Q3";
            //     $sendMessage = array('chat_id' => $chat_id, "text" =>$sendText, 'reply_markup' => array(
            //         'force_reply' => true)
            //     );
            //     $this->apiRequestJson("sendMessage", $sendMessage);
            //     $this->saveBotMessage($message_id,$sendMessage);
            //     $this->updateUserStatus($user_id,"registration","");
            // }

            return;
        }
    }

    function processMessageOld($update) {
        $this->saveRawUpdate($update);
        if($this->isUpdateExist($update)){
            return;
        }
        $message = $update["message"];
        // process incoming message
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $status = $this->getUserStatus($user_id);

        if($status==""){
            if (isset($message['text'])) {
                // incoming text message
                $text = $message['text'];
                $this->saveMessage($message);
                // echo $text;
                if (strpos($text, "/start") === 0) {
                    $this->registerUser($message);
                    $this->registerChat($message);
                    $sendText = "Selamat datang di BEATs!
                    Saya akan bertanya kepada Anda secara berkala tentang keadaan dan kesejahteraan Anda sehari-hari.
                    Sebelum melanjutkan, silahkan kunjungi lembar persetujuan di laman berikut.
                    https://www.facebook.com/notes/beats/lembar-persetujuan/2017675754940751";
                    $sendMessage = array('chat_id' => $chat_id, "text" =>$sendText, 'reply_markup' => array(
                        'keyboard' => array(array('Setuju', 'Tidak Setuju')),
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true)
                    );
                    $this->apiRequestJson("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                    $this->updateUserStatus($user_id,"registration","");
                } else {
                    $sendMessage = array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => 'Kirim /start untuk memulai');
                    $this->apiRequest("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                }

            }
        } elseif($status=="registration"){
            if (isset($message['text'])) {
                // incoming text message
                $text = $message['text'];
                $this->saveMessage($message);
                // echo $text;
                if (strpos($text, "/restart") === 0) {
                    $sendText = "Selamat datang di BEATs!
                    Saya akan bertanya kepada Anda secara berkala tentang keadaan dan kesejahteraan Anda sehari-hari.
                    Sebelum melanjutkan, silahkan kunjungi lembar persetujuan di laman berikut.
                    https://www.facebook.com/notes/beats/lembar-persetujuan/2017675754940751";
                    $sendMessage = array('chat_id' => $chat_id, "text" =>$sendText, 'reply_markup' => array(
                        'keyboard' => array(array('Setuju', 'Tidak Setuju')),
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true)
                    );
                    $this->apiRequestJson("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                } else if ($text === "Setuju") {
                    $sendMessage = array('chat_id' => $chat_id, "text" => 'Kami membutuhkan nomor kontak anda. Klik tombol kirim kontak untuk melanjutkan.',
                    'reply_markup' => array(
                        'keyboard' => array(array(array("text" =>'Kirim Kontak',"request_contact"=> true))),
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true)
                    );
                    $this->apiRequest("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                } else if ($text === "Tidak Setuju") {
                    $sendMessage = array('chat_id' => $chat_id, "text" => 'Terima kasih atas waktunya.', 'reply_markup' => array('hide_keyboard' => true));
                    $this->apiRequest("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                    $this->updateUserStatus($user_id,"registration_reject","registration");
                } else if (strpos($text, "08") === 0) {
                    $this->updateUserPhone($user_id,$text);
                    $sendMessage = array('chat_id' => $chat_id, "text" => 'Terima kasih! Saya akan menghubungi Anda lagi besok.', 'reply_markup' => array('hide_keyboard' => true));
                    $this->apiRequest("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                    $this->updateUserStatus($user_id,"registration_done","survey_one");
                } else if (strpos($text, "/stop") === 0) {
                    // stop now
                    $this->saveMessage($message);
                } else {
                    $sendMessage = array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => 'Jika ada yang ingin ditanyakan. Silahkan menghubungi staff terkait. Kirim /restart untung mengulang pendaftaran.');
                    $this->apiRequest("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                }

            } elseif(isset($message['contact'])) {
                $this->updateUser($message['contact']);
                $this->saveMessage($message);
                $sendMessage = array('chat_id' => $chat_id, "text" => 'Terima kasih! Saya akan menghubungi Anda lagi besok.', 'reply_markup' => array('hide_keyboard' => true));
                $this->apiRequest("sendMessage", $sendMessage);
                $this->saveBotMessage($message_id,$sendMessage);
                $this->updateUserStatus($user_id,"registration_done","survey_one");
            } else {
                $this->apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Jawaban yang anda kirimkan salah'));
            }
        } elseif($status=="registration_done"){
            if (isset($message['text'])) {
                // incoming text message
                $text = $message['text'];
                $this->saveMessage($message);
                $sendMessage = array('chat_id' => $chat_id, "text" => 'Anda sudah terdaftar. Silahkan menunggu instruksi selanjutnya.');
                $this->apiRequest("sendMessage", $sendMessage);
                $this->saveBotMessage($message_id,$sendMessage);
            }else{
                $this->saveMessage($message);
                $sendMessage = array('chat_id' => $chat_id, "text" => 'Anda sudah terdaftar. Silahkan menunggu instruksi selanjutnya.');
                $this->apiRequest("sendMessage", $sendMessage);
                $this->saveBotMessage($message_id,$sendMessage);
            }
        } elseif($status=="registration_reject"){
            if (isset($message['text'])) {
                // incoming text message
                $text = $message['text'];
                $this->saveMessage($message);
                if (strpos($text, "/restart") === 0) {
                    $sendText = "Selamat datang di BEATs!
                    Saya akan bertanya kepada Anda secara berkala tentang keadaan dan kesejahteraan Anda sehari-hari.
                    Sebelum melanjutkan, silahkan kunjungi lembar persetujuan di laman berikut.
                    https://www.facebook.com/notes/beats/lembar-persetujuan/2017675754940751";
                    $sendMessage = array('chat_id' => $chat_id, "text" =>$sendText, 'reply_markup' => array(
                        'keyboard' => array(array('Setuju', 'Tidak Setuju')),
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true)
                    );
                    $this->apiRequestJson("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                    $this->updateUserStatus($user_id,"registration","");
                } else {
                    $sendMessage = array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => 'Jika ada yang ingin ditanyakan. Silahkan menghubungi staff terkait. Kirim /restart untung mengulang pendaftaran.');
                    $this->apiRequest("sendMessage", $sendMessage);
                    $this->saveBotMessage($message_id,$sendMessage);
                }

            } else {
                $this->saveMessage($message);
                $sendMessage = array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => 'Jika ada yang ingin ditanyakan. Silahkan menghubungi staff terkait. Kirim /restart untung mengulang pendaftaran.');
                $this->apiRequest("sendMessage", $sendMessage);
                $this->saveBotMessage($message_id,$sendMessage);
            }
        } elseif($status=="survey_one"){
            if (isset($message['text'])) {
                // incoming text message
                $text = $message['text'];
                $this->saveMessage($message);
            }
        }
    }

    function saveRawUpdate($update){
        $update_id = $update['update_id'];
        $content = json_encode($update);
        $this->db->query("INSERT INTO raw (update_id,content) VALUES($update_id,'$content')");
    }


    function isUpdateExist($update){
        $update_id = $update['update_id'];
        if($this->db->query("SELECT update_id FROM updates WHERE update_id='$update_id'")->num_rows()>0){
            return true;
        }
        $this->db->query("INSERT INTO updates (update_id) VALUES($update_id)");
        return false;
    }

    function saveBotMessage($message_id,$message){
        $from_user = 999999999;
        $dates = date("Y-m-d H:i:s");
        $chat_id = $message['chat_id'];
        $texts = $message['text'];
        $connected_website = "";
        $this->db->query("INSERT INTO messages (message_id,from_user,dates,chat_id,texts,connected_website) VALUES($message_id,$from_user,'$dates',$chat_id,'$texts','$connected_website')");
    }

    function saveMessage($message){
        $message_id = $message['message_id'];
        $from_user = $message['from']['id'];
        $dates = date("Y-m-d H:i:s",$message['date']);
        $chat_id = $message['chat']['id'];
        $texts = $message['text'];
        $connected_website = isset($message['connected_website'])?$message['connected_website']:"";

        if(isset($message['audio'])){
            $attachment = json_encode($message['audio']);
            $attachment_type = "audio";
        }elseif(isset($message['document'])){
            $attachment = json_encode($message['document']);
            $attachment_type = "document";
        }elseif(isset($message['game'])){
            $attachment = json_encode($message['game']);
            $attachment_type = "game";
        }elseif(isset($message['photo'])){
            $attachment = json_encode($message['photo']);
            $attachment_type = "photo";
        }elseif(isset($message['sticker'])){
            $attachment = json_encode($message['sticker']);
            $attachment_type = "sticker";
        }elseif(isset($message['video'])){
            $attachment = json_encode($message['video']);
            $attachment_type = "video";
        }elseif(isset($message['voice'])){
            $attachment = json_encode($message['voice']);
            $attachment_type = "voice";
        }elseif(isset($message['video_note'])){
            $attachment = json_encode($message['video_note']);
            $attachment_type = "video_note";
        }elseif(isset($message['contact'])){
            $attachment = json_encode($message['contact']);
            $attachment_type = "contact";
        }elseif(isset($message['location'])){
            $attachment = json_encode($message['location']);
            $attachment_type = "location";
        }else{
            $attachment = "";
            $attachment_type = "";
        }
        $this->db->query("INSERT INTO messages (message_id,from_user,dates,chat_id,texts,attachment,attachment_type,connected_website) VALUES($message_id,$from_user,'$dates',$chat_id,'$texts','$attachment','$attachment_type','$connected_website')");
    }

    function registerChat($message){
        $chat_id = $message['chat']['id'];
        if($this->db->query("SELECT id FROM chats WHERE id='$chat_id'")->num_rows()>0){
            return;
        }
        $type = $message['chat']['type'];
        $title = isset($message['chat']['title'])?$message['chat']['title']:"";
        $username = isset($message['chat']['username'])?$message['chat']['username']:"";
        $first_name = $message['chat']['first_name'];
        $last_name = $message['chat']['last_name'];
        $this->db->query("INSERT INTO chats VALUES($chat_id,'$type','$title','$username','$first_name','$last_name')");
    }

    function registerUser($message){
        $user_id = $message['from']['id'];
        if($this->db->query("SELECT id FROM users WHERE user_id='$user_id'")->num_rows()>0){
            return;
        }
        $is_bot = isset($message['from']['is_bot'])?$message['from']['is_bot']?1:0:0;
        $first_name = $message['from']['first_name'];
        $last_name = $message['from']['last_name'];
        $username = isset($message['from']['username'])?$message['from']['username']:"";
        $language_code = isset($message['from']['language_code'])?$message['from']['language_code']:"";
        $this->db->query("INSERT INTO users (user_id,is_bot,first_name,last_name,username,language_code) VALUES($user_id,$is_bot,'$first_name','$last_name','$username','$language_code')");
        $this->db->query("INSERT INTO status (user_id,status) VALUES ($user_id,'registration')");
    }

    function updateUserPhone($user_id,$phone){
        $this->db->query("UPDATE users SET contact='$phone' WHERE user_id=$user_id");
    }

    function updateUser($contact){
        $phone = $contact['phone_number'];
        $first_name = $contact['first_name'];
        if(isset($contact['user_id'])){
            $user_id = $contact['user_id'];
            $phone = $contact['phone_number'];
            $this->db->query("UPDATE users SET contact='$phone' WHERE user_id=$user_id");
        }elseif($this->db->query("SELECT id FROM users WHERE first_name='$first_name'")->num_rows()>0){
            $this->db->query("UPDATE users SET contact='$phone' WHERE first_name=$first_name");
        }else{
            $last_name = isset($contact['last_name'])?$contact['last_name']:"";
            $this->db->query("INSERT INTO users (first_name,last_name,contact) VALUES ('$first_name','$last_name','$phone')");
        }

    }

    function getUserStatus($user_id){
        if($this->db->query("SELECT id FROM users WHERE user_id='$user_id'")->num_rows()>0){
            return $this->db->query("SELECT status FROM status WHERE user_id=$user_id")->row()->status;;
        }

        return "";

    }

    function updateUserStatus($user_id,$status,$next=""){
        $this->db->query("UPDATE status SET status='$status', next='$next' WHERE user_id=$user_id");
    }
}
