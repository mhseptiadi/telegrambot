<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Response extends CI_Controller {

	public function index()
	{
		$arr = array(
			'inline_keyboard' => array(array(
				array('text' => 'OK', 'callback_data' => 'callback1'),
				array('text' => 'Not OK', 'callback_data' => 'callback2')
			)));
		echo json_encode($arr);
	}

	public function get($type=""){
        $this->load->model('ResponseModel','rs');
        $data = $this->rs->getResponse($type);
        echo "<table>
        <tr>
            <td>username</td><td>first_name</td><td>last_name</td><td>message</td><td>response</td>
        </tr>
        ";
        foreach ($data as $row){
            echo "<tr>";
            foreach ($row as $val){
                echo "<td>$val</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
	}

}
