<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Telegram extends CI_Controller {

	public function index()
	{
		echo "telegram bot";
	}

	public function webhook($reg=""){

		$this->load->model('TelegramModel','tg');

// 		var_dump($this->tg->apiRequest('getWebhookInfo',[]));
// 		exit;

		if ($reg() == 'reg') {
			// if run from console, set or delete webhook
			$res = $this->tg->apiRequest('setWebhook', array('url' => isset($argv[1]) && $argv[1] == 'delete' ? '' : WEBHOOK_URL));
			var_dump($res);
			exit;
		}


		$content = file_get_contents("php://input");
		$update = json_decode($content, true);

		if (!$update) {
			echo json_encode(["error"=>"receive wrong update, must not happen"]);
			exit;
		}

		if (isset($update["message"])) {
			$this->tg->processMessage($update);
		}
	}

	public function sendMessage($chat_id="",$text=""){
	    if($chat_id==""){
	        echo json_encode(["error","chat id cannot be empty"]);
	        exit;
	    }
	    if($text==""){
	        echo json_encode(["error","text cannot be empty"]);
	        exit;
	    }
	    $text = urldecode($text);
	    $this->load->model('TelegramModel','tg');
	    $message_id = time();
	    $sendMessage = array('chat_id' => $chat_id, "text" => $text, 'reply_markup' => array(
                    'hide_keyboard' => true));
        $this->tg->apiRequest("sendMessage", $sendMessage);
        $this->tg->saveBotMessage($message_id,$sendMessage);
	}
}
