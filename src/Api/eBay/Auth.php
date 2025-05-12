<?php
// src/Api/eBay/Auth.php
namespace Four\ScrEbaySync\Api\eBay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

class Auth
{
    private string $clientId;
    private string $clientSecret;
    private string $ruName;
    private string $accessToken = '';
    private string $refreshToken = '';
    private int $expiresAt = 0;
    private bool $isSandbox;
    private Logger $logger;

    // API endpoints
    private const PRODUCTION_API_ENDPOINT = 'https://api.ebay.com';
    private const SANDBOX_API_ENDPOINT = 'https://api.sandbox.ebay.com';
    private const PRODUCTION_AUTH_ENDPOINT = 'https://api.ebay.com/identity/v1/oauth2';
    private const SANDBOX_AUTH_ENDPOINT = 'https://api.sandbox.ebay.com/identity/v1/oauth2';

    // OAuth scopes needed for inventory and orders
    private const SCOPES = [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.marketing',
        'https://api.ebay.com/oauth/api_scope/sell.marketing.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.account',
        'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.finances',
        'https://api.ebay.com/oauth/api_scope/sell.payment.dispute'
    ];

    /**
     * @param string $clientId The eBay App Client ID
     * @param string $clientSecret The eBay App Client Secret
     * @param string $ruName The eBay Redirect URL name
     * @param bool $isSandbox Whether to use the eBay Sandbox environment
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        string $clientId,
        string $clientSecret,
        string $ruName,
        bool $isSandbox = false,
        ?Logger $logger = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->ruName = $ruName;
        $this->isSandbox = $isSandbox;
        $this->logger = $logger ?? new Logger('ebay_auth');
        $this->expiresAt = 0;
        
        // Load stored tokens if available
        $this->loadTokens();
    }

    /**
     * Check if we have a valid access token
     *
     * @return bool Whether we have a valid access token
     */
    public function hasAccessToken(): bool
    {
        return !empty($this->accessToken) && !empty($this->refreshToken);
    }

    /**
     * Get the OAuth URL for user authorization
     *
     * @return string The authorization URL
     */
    public function getAuthorizationUrl(): string
    {
        // User authorization still uses the auth.ebay.com domain
        $authBaseUrl = $this->isSandbox ? 'https://auth.sandbox.ebay.com' : 'https://auth.ebay.com';
        $scopes = implode(' ', self::SCOPES);
        
        $url = $authBaseUrl . '/oauth2/authorize' .
            '?client_id=' . urlencode($this->clientId) .
            '&response_type=code' .
            '&redirect_uri=' . urlencode($this->ruName) .
            '&scope=' . urlencode($scopes);
        
        $this->logger->info("Generated authorization URL: {$url}");
        
        return $url;
    }

    /**
     * Exchange authorization code for tokens
     *
     * @param string $code The authorization code
     * @return bool Success status
     */
    public function exchangeCodeForTokens(string $code): bool
    {
        try {
            $client = new Client();
            $authEndpoint = $this->isSandbox ? self::SANDBOX_AUTH_ENDPOINT : self::PRODUCTION_AUTH_ENDPOINT;
            
            $this->logger->info("Exchanging authorization code for tokens with endpoint: {$authEndpoint}/token");
            $this->logger->debug("Using RuName: {$this->ruName}");
            
            $options = [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)
                ],
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->ruName
                ]
            ];
            
            $this->logger->debug("Request options: " . json_encode($options));
            
            $response = $client->post($authEndpoint . '/token', $options);
            
            $data = json_decode($response->getBody()->getContents(), true);
            $this->logger->debug("Token response: " . json_encode($data));
            
            if (isset($data['access_token'], $data['refresh_token'], $data['expires_in'])) {
                $this->accessToken = $data['access_token'];
                $this->refreshToken = $data['refresh_token'];
                $this->expiresAt = time() + $data['expires_in'];
                
                // Save tokens
                $this->saveTokens();
                $this->logger->info("Successfully exchanged authorization code for tokens");
                
                return true;
            }
            
            $this->logger->error("Failed to exchange code: incomplete token data received");
            return false;
        } catch (GuzzleException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->logger->error('Failed to exchange code for tokens: ' . $e->getMessage());
            $this->logger->error('Error response body: ' . $errorBody);
            return false;
        }
    }

    /**
     * Get a valid access token (refresh if needed)
     *
     * @return string The access token
     * @throws \RuntimeException If token refresh fails
     */
    public function getAccessToken(): string
    {
        // Check if token expired or about to expire
        if ($this->expiresAt <= time() + 60) {
            $this->refreshAccessToken();
        }
        
        return $this->accessToken;
    }

    /**
     * Refresh the access token using the refresh token
     *
     * @return bool Success status
     * @throws \RuntimeException If token refresh fails
     */
    private function refreshAccessToken(): bool
    {
        if (empty($this->refreshToken)) {
            throw new \RuntimeException('No refresh token available');
        }
        
        try {
            $client = new Client();
            $authEndpoint = $this->isSandbox ? self::SANDBOX_AUTH_ENDPOINT : self::PRODUCTION_AUTH_ENDPOINT;
            
            $this->logger->info("Refreshing access token with endpoint: {$authEndpoint}/token");
            
            $options = [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)
                ],
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken,
                    'scope' => implode(' ', self::SCOPES)
                ]
            ];
            
            $response = $client->post($authEndpoint . '/token', $options);
            
            $data = json_decode($response->getBody()->getContents(), true);
            $this->logger->debug("Refresh token response: " . json_encode($data));
            
            if (isset($data['access_token'], $data['expires_in'])) {
                $this->accessToken = $data['access_token'];
                $this->expiresAt = time() + $data['expires_in'];
                
                // If a new refresh token is provided, update it
                if (isset($data['refresh_token'])) {
                    $this->refreshToken = $data['refresh_token'];
                }
                
                // Save tokens
                $this->saveTokens();
                $this->logger->info("Successfully refreshed access token");
                
                return true;
            }
            
            throw new \RuntimeException('Invalid token response: ' . json_encode($data));
        } catch (GuzzleException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->logger->error('Failed to refresh token: ' . $e->getMessage());
            $this->logger->error('Error response body: ' . $errorBody);
            throw new \RuntimeException('Failed to refresh token: ' . $e->getMessage() . ' - ' . $errorBody);
        }
    }

    /**
     * Get the API base URL
     *
     * @return string The base URL
     */
    public function getApiBaseUrl(): string
    {
        return $this->isSandbox ? self::SANDBOX_API_ENDPOINT : self::PRODUCTION_API_ENDPOINT;
    }

    /**
     * Check if the instance is using sandbox mode
     *
     * @return bool Sandbox status
     */
    public function isSandbox(): bool
    {
        return $this->isSandbox;
    }

    /**
     * Save tokens to storage
     */
    private function saveTokens(): void
    {
        $data = [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'sandbox' => $this->isSandbox
        ];
        
        $tokenFile = $this->getTokenFilePath();
        
        // Ensure directory exists
        $dir = dirname($tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($tokenFile, json_encode($data));
    }

    /**
     * Load tokens from storage
     */
    private function loadTokens(): void
    {
        $tokenFile = $this->getTokenFilePath();
        
        if (file_exists($tokenFile)) {
            $data = json_decode(file_get_contents($tokenFile), true);
            
            if ($data && isset($data['sandbox']) && $data['sandbox'] === $this->isSandbox) {
                $this->accessToken = $data['access_token'] ?? '';
                $this->refreshToken = $data['refresh_token'] ?? '';
                $this->expiresAt = $data['expires_at'] ?? 0;
            }
        }
    }

    /**
     * Get the token file path
     *
     * @return string The file path
     */
    private function getTokenFilePath(): string
    {
        $environment = $this->isSandbox ? 'sandbox' : 'production';
        
        // Create var directory if it doesn't exist
        $varDir = __DIR__ . '/../../../var';
        if (!is_dir($varDir)) {
            mkdir($varDir, 0755, true);
        }
        
        return $varDir . '/ebay_tokens_' . $environment . '.json';
    }
}