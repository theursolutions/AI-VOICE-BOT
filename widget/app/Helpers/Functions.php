<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;

/**
 * Securely Get base URL
 */
if (!function_exists('getBaseUrl')) {
    function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443))
            ? "https://" : "http://";

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Remove script name (index.php etc.)
        $scriptDir = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);

        return rtrim($protocol . $host . $scriptDir, '/');
    }
}

/**
 * Securely call a 3rd-party API
 *
 * @param string $url The API endpoint
 * @param string $method HTTP method (GET, POST, PUT, DELETE)
 * @param array $headers Optional headers
 * @param array $data Optional request body (for POST/PUT)
 * @param int $timeout Request timeout in seconds
 * @return array Response data or error message
 */
if (!function_exists('callApi')) {
    function callApi(string $url, string $method = 'GET', array $headers = [], array $data = [], int $timeout = 10, bool $asFormData = false): array
    {
        $client = new \GuzzleHttp\Client([
            'verify'  => true,     // SSL verification
            'timeout' => $timeout, // request timeout
        ]);

        try {
            $options = [
                'headers' => $headers,
            ];

            if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
                if ($asFormData) {
                    // Send as multipart/form-data
                    $options['multipart'] = [];
                    foreach ($data as $key => $value) {
                        if ($key === 'message_file' && is_string($value)) {
                            // if you passed path
                            $options['multipart'][] = [
                                'name'     => $key,
                                'contents' => fopen($value, 'r'),
                                'filename' => basename($value)
                            ];
                        } elseif ($key === 'message_file' && is_resource($value)) {
                            // if you already passed fopen()
                            $options['multipart'][] = [
                                'name'     => $key,
                                'contents' => $value,
                                'filename' => $fileName ?? 'upload.wav'
                            ];
                        } else {
                            $options['multipart'][] = [
                                'name'     => $key,
                                'contents' => $value,
                            ];
                        }
                    }
                } else {
                    // Send as JSON
                    $options['json'] = $data;
                }
            } elseif (strtoupper($method) === 'GET' && !empty($data)) {
                $options['query'] = $data;
            }

            $response = $client->request($method, $url, $options);

            return [
                'success' => true,
                'status'  => $response->getStatusCode(),
                'data'    => json_decode($response->getBody()->getContents(), true)
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return [
                'success' => false,
                'status'  => $e->getCode(),
                'error'   => $e->getMessage()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status'  => 500,
                'error'   => $e->getMessage()
            ];
        }
    }

}