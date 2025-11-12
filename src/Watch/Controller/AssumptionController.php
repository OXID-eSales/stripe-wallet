<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Controller;

use OxidEsales\Eshop\Core\Controller\BaseController;
use OxidSolutionCatalysts\Payments\Watch\Service\AuthenticationService;
use OxidSolutionCatalysts\Payments\Watch\Service\AssumptionParser;
use OxidSolutionCatalysts\Payments\Watch\Service\QueryBuilder;
use OxidSolutionCatalysts\Payments\Watch\Service\AuditLogger;
use OxidSolutionCatalysts\Payments\Watch\Exception\AuthenticationException;
use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * PaymentWatch Assumption Controller
 *
 * HTTP endpoint for database state verification in E2E tests.
 *
 * Route: POST /paymentwatch/assume
 *
 * Request:
 * ```json
 * {
 *   "assumption": {
 *     "osc_payment_contract.OXSTATE": "committed",
 *     "where": {"OXID": "contract-123"},
 *     "operator": "=="
 *   }
 * }
 * ```
 *
 * Response:
 * ```json
 * {
 *   "assumption": true,
 *   "query_time_ms": 12.5,
 *   "matched_rows": 1,
 *   "actual_value": "committed",
 *   "expected_value": "committed"
 * }
 * ```
 */
class AssumptionController extends BaseController
{
    public function __construct(
        private readonly AuthenticationService $authService,
        private readonly AssumptionParser $parser,
        private readonly QueryBuilder $queryBuilder,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    /**
     * Handle assumption verification request
     *
     * @return void
     */
    public function assume(): void
    {
        $startTime = microtime(true);
        $requestId = $this->getRequestId();

        try {
            // 1. Authenticate
            $clientIp = $this->getClientIp();
            $apiKey = $this->getApiKey();

            $this->authService->authenticate($clientIp, $apiKey);

            // 2. Parse request
            $body = $this->getRequestBody();
            $assumptionRequest = $this->parser->parse($body);

            // 3. Execute query
            $response = $this->queryBuilder->execute($assumptionRequest);

            // 4. Audit log
            $this->auditLogger->logRequest(
                $requestId,
                $clientIp,
                $apiKey,
                $assumptionRequest,
                $response
            );

            // 5. Return JSON response
            $this->sendJsonResponse($response->toArray(), 200);
        } catch (AuthenticationException $e) {
            $this->auditLogger->logAuthenticationFailure(
                $requestId,
                $this->getClientIp(),
                $e->getMessage()
            );
            $this->sendJsonResponse(['error' => 'Unauthorized'], 401);
        } catch (ValidationException $e) {
            $this->auditLogger->logValidationError(
                $requestId,
                $this->getClientIp(),
                $e->getMessage()
            );
            $this->sendJsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->logger->error('PaymentWatch error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendJsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Get client IP address
     *
     * @return string Client IP address
     */
    private function getClientIp(): string
    {
        // Check for forwarded IP (proxy/load balancer)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get API key from request headers
     *
     * @return string API key
     * @throws AuthenticationException If API key is missing
     */
    private function getApiKey(): string
    {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

        if (empty($apiKey)) {
            throw new AuthenticationException('Missing X-API-Key header');
        }

        return $apiKey;
    }

    /**
     * Get request ID from headers or generate new one
     *
     * @return string Request ID
     */
    private function getRequestId(): string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('pwreq_', true);
    }

    /**
     * Get request body as array
     *
     * @return array<string, mixed> Parsed request body
     * @throws ValidationException If JSON is invalid
     */
    private function getRequestBody(): array
    {
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody)) {
            throw new ValidationException('Empty request body');
        }

        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationException('Invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($body)) {
            throw new ValidationException('Request body must be a JSON object');
        }

        return $body;
    }

    /**
     * Send JSON response
     *
     * @param array<string, mixed> $data Response data
     * @param int $statusCode HTTP status code
     * @return void
     */
    private function sendJsonResponse(array $data, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('X-Request-ID: ' . $this->getRequestId());

        echo json_encode($data, JSON_THROW_ON_ERROR);
        exit;
    }
}
