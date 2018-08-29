<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class QuestionModel extends CI_Model {

    function __construct() {
        parent::__construct();
        $this->db = $this->load->database('telegram', TRUE);
    }

    function insert($data){

        $allowedFields = [
            "message","answer","todo",
            "trigger",
            "prev",
            "next"
        ];

        if (!isset($data['message'])){
            return "No message inputted";
        }elseif($data['message'] == ""){
            return "No message inputted";
        }

        $next = "false";
        if (isset($data['prev'])){
            $query = "SELECT next FROM question WHERE id=?";
            $exec = $this->db->query($query, [$data['prev']]);
            if($exec->num_rows() == 0){
                return "No prev found";
            }else{
                $next = array_filter(explode(",",$exec->row()->next));
            }
        }

        $fields = [];
        $binds = [];
        $contents = [];
        foreach ($data as $key=>$val){
            if (!in_array($key, $allowedFields)) {
                unset($data[$key]);
            }else{
                array_push($fields,"question.".$key);
                array_push($binds,'?');
                array_push($contents,$val);
            }
        }
        
        $query = "INSERT INTO question (".implode(",",$fields).")
        VALUES (".implode(",",$binds).")";
        $this->db->query($query, $contents);
        $lastId = $this->db->insert_id();
        if($next != "false" && isset($data['prev'])){
            array_push($next,$lastId);
            $query = "UPDATE question
            SET question.next = ?
            WHERE question.id = ?;";
            
            $this->db->query($query, [implode(",",$next),$data['prev']]);
        }
        return $next;
        // return $next;

    }

    function insertbulk($databulk){

        foreach ($databulk as $data) {
            $allowedFields = [
                "key","type",
                "message","answer","todo",
                "trigger",
                "prev",
                "next"
            ];

            if (!isset($data['message'])){
                echo "No message inputted\n";
            }elseif($data['message'] == ""){
                echo "No message inputted\n";
            }

            $next = 'false';
            $nextArr = [];
            if (isset($data['prev']) && $data['prev'] != ''){
                $query = "SELECT question.next,question.key FROM question WHERE question.key in ? and question.type = ?";
                $prevArr = explode(',',$data['prev']);
                $exec = $this->db->query($query, [$prevArr,$data['type']]);
                if($exec->num_rows() == 0){
                    echo "No prev key found".$data['prev']."\n";
                }else{
                    foreach ($exec->result() as $row)
                    {
                        $next = array_filter(explode(",",$row->next));
                        $nextArr[$row->key] = $next;
                    }
                }
            }

            $fields = [];
            $binds = [];
            $contents = [];
            foreach ($data as $key=>$val){
                if (!in_array($key, $allowedFields)) {
                    unset($data[$key]);
                }else{
                    if($val != null){
                        array_push($fields,"question.".$key);
                        array_push($binds,'?');
                        if($key == 'answer' && $val != ''){
                            if(is_object($val)){
                                $choices = [];
                                foreach($val as $key2 => $val2){
                                    $choice = (object) array('text' => $val2,'callback_data' => $key2);
                                    array_push($choices,$choice);
                                }
                                $keyboard = (object) array('inline_keyboard' => [$choices]);
                                $val = json_encode($keyboard);
                            }elseif(is_array($val)){
                                $choices = [];
                                foreach($val as $val2){
                                    array_push($choices,$val2);
                                }
                                $keyboard = (object) array('keyboard' => [$choices],
                                'one_time_keyboard' => true,
                                'resize_keyboard' => true);
                                $val = json_encode($keyboard);
                            }
                        }
                        array_push($contents,$val);
                    }
                }
            }
            
            $query = "INSERT INTO question (".implode(",",$fields).")
            VALUES (".implode(",",$binds).")";
            $this->db->query($query, $contents);
            $lastId = $this->db->insert_id();
            if($next != "false" && isset($data['prev'])){
                foreach($nextArr as $key => $next){
                    array_push($next,$data['key']);
                    $query = "UPDATE question
                    SET question.next = ?
                    WHERE question.key in ? and question.type = ?;";
                    $prevArr = explode(',',$key);

                    $this->db->query($query, [implode(",",$next),$prevArr,$data['type']]);
                }
            }
        }
        return "ok";
        // return $next;

    }
}