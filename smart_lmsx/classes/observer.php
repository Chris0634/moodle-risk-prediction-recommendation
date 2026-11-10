<?php
namespace local_smart_lmsx;

defined('MOODLE_INTERNAL') || die();

class observer {

    public static function store_activity(\core\event\base $event) {
        $data = $event->get_data();

        $userid = !empty($data['relateduserid']) ? $data['relateduserid'] : $data['userid'];

        if ($userid <= 0) {
            return;
        }

        $payload = [
            'userid'    => (int)$userid,
            'courseid'  => (int)$data['courseid'],
            'eventname' => $data['eventname'],
            'time'      => time(),
            'action'    => $data['action'] ?? ''
        ];

        self::predict_risk($payload);
    }

    protected static function predict_risk($payload) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $url = 'http://127.0.0.1:8000/predict'; 
        
        $curl = new \curl();
        $curl->setHeader('Content-Type: application/json');
        $curl->setopt(array('CURLOPT_TIMEOUT' => 3));
        $json_payload = json_encode($payload);
        $result = $curl->post($url, $json_payload);

        if ($result) {
            $response = json_decode($result);
        }
    }
}