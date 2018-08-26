<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Question extends CI_Controller {

	public function index()
	{
		$arr = array(
			'inline_keyboard' => array(array(
				array('text' => 'OK', 'callback_data' => 'callback1'),
				array('text' => 'Not OK', 'callback_data' => 'callback2')
			)));
		echo json_encode($arr);
	}

	public function insert(){
		$this->load->model('QuestionModel','qs');
        $jsonArray = json_decode(file_get_contents('php://input'),true); 
        $return = $this->qs->insert($jsonArray);
		print_r($return);
		// var_dump($return);
	}

	public function insertbulk(){
		$this->load->model('QuestionModel','qs');
        $jsonArray = json_decode(file_get_contents('php://input'),true); 
        // print_r($jsonArray);
        $return = $this->qs->insertbulk($jsonArray);
		print_r($return);
		// var_dump($return);
	}

}
