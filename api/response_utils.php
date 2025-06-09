<?php

class ApiResponse {
    public static function success($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', // Changed from 'success' => true
            'data' => $data
        ]);
    }

    public static function error($message, $statusCode = 400, $details = null) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        $response = [
            'status' => 'error', // Changed from 'success' => false
            'message' => $message // Moved up from 'error']['message']
        ];
        if ($details !== null) {
            $response['details'] = $details; // Moved up from 'error']['details']
        }
        echo json_encode($response);
    }
}
?>
