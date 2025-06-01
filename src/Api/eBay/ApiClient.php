<?php
// src/Api/eBay/ApiClient.php
namespace Four\ScrEbaySync\Api\eBay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use RuntimeException;

abstract class ApiClient
{
    protected Auth $auth;
    protected Client $client;
    protected Logger $logger;
    protected string $apiVersion = 'v1';

    /**
     * @param Auth $auth The eBay Authentication instance
     * @param Logger|null $logger Optional logger
     */
    public function __construct(Auth $auth, ?Logger $logger = null)
    {
        $this->auth = $auth;
        $this->logger = $logger ?? new Logger('ebay_api');
        $this->client = new Client([
            'base_uri' => $auth->getApiBaseUrl(),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
    }

    /**
     * Execute a GET request
     *
     * @param string $endpoint API endpoint
     * @param array $query Query parameters
     * @return array Response data
     * @throws RuntimeException If request fails
     */
    protected function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $query]);
    }

    /**
     * Execute a POST request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array Response data
     * @throws RuntimeException If request fails
     */
    protected function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    /**
     * Execute a PUT request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array Response data
     * @throws RuntimeException If request fails
     */
    protected function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    /**
     * Execute a DELETE request
     *
     * @param string $endpoint API endpoint
     * @return array Response data
     * @throws RuntimeException If request fails
     */
    protected function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Execute an API request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $options Request options
     * @return array Response data
     * @throws RuntimeException If request fails
     */
    protected function request(string $method, string $endpoint, array $options = []): array
    {
        try {
            // Add authorization header
            $options['headers'] = array_merge(
                $options['headers'] ?? [], [
                    'Authorization' => 'Bearer ' . $this->auth->getAccessToken(),
                    'Content-Language' => 'de-DE'
                ]
            );
            
            $response = $this->client->request($method, $endpoint, $options);
            $data = json_decode($response->getBody()->getContents(), true);
            
            return $data ?: [ 'statusCode' => $response->getStatusCode() ];
        } catch (GuzzleException $e) {
            $response = "";
            if ($e instanceof ClientException) {
                $response = $e->getResponse()->getBody()->getContents();
            }
            $this->logger->error('API request failed: ' . $e->getMessage(), [
                'method' => $method,
                'endpoint' => $endpoint,
                'options' => $options,
                'response' => $response,
            ]);
            
            throw new RuntimeException('eBay API request failed: ' . $e->getMessage(), previous: $e);
        }
    }
}