<?php
declare(strict_types=1);

namespace Four\ScrEbaySync\Controllers;

use Four\ScrEbaySync\Services\EbayWebhook\WebhookService;
use Monolog\Logger;

/**
 * Webhook Controller for eBay Push Notifications
 * 
 * Handles incoming HTTP requests from eBay's notification system.
 * Provides endpoints for webhook verification and notification processing.
 */
class WebhookController
{
    private WebhookService $webhookService;
    private Logger $logger;
    
    public function __construct(WebhookService $webhookService, Logger $logger)
    {
        $this->webhookService = $webhookService;
        $this->logger = $logger;
    }
    
    /**
     * Handle eBay webhook notifications
     * 
     * @return void
     */
    public function handleWebhook(): void
    {
        try {
            // Get request method
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            
            if ($method === 'GET') {
                // Handle webhook verification challenge
                $this->handleVerificationChallenge();
                return;
            }
            
            if ($method !== 'POST') {
                $this->respondWithError(405, 'Method not allowed');
                return;
            }
            
            // Get headers
            $signature = $_SERVER['HTTP_X_EBAY_SIGNATURE'] ?? '';
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            // Validate content type
            if (!str_contains($contentType, 'application/json')) {
                $this->respondWithError(400, 'Invalid content type');
                return;
            }
            
            // Get raw POST data
            $payload = file_get_contents('php://input');
            
            if (empty($payload)) {
                $this->respondWithError(400, 'Empty payload');
                return;
            }
            
            $this->logger->info('Webhook notification received', [
                'content_length' => strlen($payload),
                'content_type' => $contentType,
                'has_signature' => !empty($signature),
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Process the webhook
            $result = $this->webhookService->processWebhook($payload, $signature);
            
            // Respond based on processing result
            if ($result['status'] === 'success') {
                $this->respondWithSuccess($result);
            } elseif ($result['status'] === 'error') {
                $this->respondWithError(400, $result['message']);
            } else {
                $this->respondWithSuccess($result);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Webhook controller error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->respondWithError(500, 'Internal server error');
        }
    }
    
    /**
     * Handle eBay webhook verification challenge
     * 
     * eBay sends a GET request with a challenge parameter during setup
     */
    private function handleVerificationChallenge(): void
    {
        $challengeCode = $_GET['challenge_code'] ?? '';
        $verificationToken = $_GET['verification_token'] ?? '';
        
        $expectedToken = $_ENV['WEBHOOK_VERIFICATION_TOKEN'] ?? '';
        
        $this->logger->info('Webhook verification challenge received', [
            'challenge_code' => $challengeCode,
            'verification_token' => $verificationToken,
            'token_valid' => ($verificationToken === $expectedToken)
        ]);
        
        // Verify the token matches our configuration
        if (empty($expectedToken) || $verificationToken !== $expectedToken) {
            $this->respondWithError(401, 'Invalid verification token');
            return;
        }
        
        if (empty($challengeCode)) {
            $this->respondWithError(400, 'Missing challenge code');
            return;
        }
        
        // Respond with the challenge code (eBay verification requirement)
        header('Content-Type: application/json');
        http_response_code(200);
        
        echo json_encode([
            'challengeResponse' => $challengeCode
        ]);
        
        $this->logger->info('Webhook verification challenge completed successfully');
    }
    
    /**
     * Send success response
     * 
     * @param array $data Response data
     */
    private function respondWithSuccess(array $data): void
    {
        header('Content-Type: application/json');
        http_response_code(200);
        
        echo json_encode([
            'status' => 'success',
            'timestamp' => date('c'),
            'data' => $data
        ]);
        
        $this->logger->info('Webhook processed successfully', $data);
    }
    
    /**
     * Send error response
     * 
     * @param int $code HTTP status code
     * @param string $message Error message
     */
    private function respondWithError(int $code, string $message): void
    {
        header('Content-Type: application/json');
        http_response_code($code);
        
        echo json_encode([
            'status' => 'error',
            'error' => $message,
            'timestamp' => date('c')
        ]);
        
        $this->logger->error('Webhook error response', [
            'code' => $code,
            'message' => $message
        ]);
    }
}
