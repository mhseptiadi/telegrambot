<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class ResponseModel extends CI_Model {

    function __construct() {
        parent::__construct();
        $this->db = $this->load->database('telegram', TRUE);
    }

    function getResponse($type = "",$username = ""){

        $filter = "where 1 ";
        if ($type != ""){
            $filter .= " and response.questionType = '$type'";
        }
        if ($username != ""){
            $filter .= " and users.username = '$username'";
        }

        $query = "SELECT users.username, users.first_name, users.last_name, question.message, response.response 
        FROM response 
            JOIN question ON question.key = response.questionKey AND question.type = response.questionType
            LEFT JOIN users ON users.user_id = response.userId $filter ;";

        $data = [];
        $exec = $this->db->query($query);
        if($exec->num_rows() == 0){
            echo "No response found";
        }else{
            foreach ($exec->result() as $row)
            {
                array_push($data,$row);
            }
        }
        return $data;
    }
}