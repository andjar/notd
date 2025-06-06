<?php

class ApiResponse {
    public static function success($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
    }

    public static function error($message, $statusCode = 400, $details = null) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        $response = [
            'success' => false,
            'error' => [
                'message' => $message
            ]
        ];
        if ($details !== null) {
            $response['error']['details'] = $details;
        }
        echo json_encode($response);
    }
}
?>
