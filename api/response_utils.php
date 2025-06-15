<?php

if (!function_exists('send_json_response')) {
    function send_json_response($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('send_json_error_response')) {
    function send_json_error_response($message, $statusCode = 400, $details = null) {
        $response = [
            'status' => 'error',
            'message' => $message
        ];
        
        if ($details !== null) {
            $response['details'] = $details;
        }
        
        send_json_response($response, $statusCode);
    }
}

class ApiResponse {
    public static function success($data, $statusCode = 200, $metadata = []) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        $response = [
            'status' => 'success',
            'data' => $data
        ];
        
        if (!empty($metadata)) {
            $response = array_merge($response, $metadata);
        }

        echo json_encode($response);
    }

    public static function error($message, $statusCode = 400, $details = null) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        $response = [
            'status' => 'error',
            'message' => $message
        ];

        if ($details !== null) {
            $response['details'] = $details;
        }

        echo json_encode($response);
    }
}
?>