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

		if ($reg == 'reg') {
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

	public function sendMessage($BOT_TOKEN="",$message="",$user_id=""){
		if($BOT_TOKEN!=BOT_TOKEN){
			echo json_encode(["error"=>"wrong token number"]);
			exit;
		}
		if($user_id==""){
			echo json_encode(["error","user id cannot be empty"]);
			exit;
		}
		if($message==""){
			echo json_encode(["error","message cannot be empty"]);
			exit;
		}
		$message = urldecode($message);
		$this->load->model('TelegramModel','tg');
		$message_id = time();
		$sendMessage = array('chat_id' => $user_id, "text" => $message, 'reply_markup' => array(
					'hide_keyboard' => true));
		$this->tg->apiRequest("sendMessage", $sendMessage);
		$this->tg->saveBotMessage($message_id,$sendMessage);
		echo json_encode(["success"=>true]);

	}
}
