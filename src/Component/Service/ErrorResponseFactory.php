<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Component\Service;

/**
 * ErrorResponseFactory - Standardizes error responses across the application
 *
 * Provides consistent error formatting for:
 * - Validation errors
 * - Payment errors
 * - System errors
 * - Network errors
 *
 * Frontend can parse these standardized responses without reloading
 */
class ErrorResponseFactory
{
    // Error codes for frontend to handle specifically
    public const CODE_VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const CODE_PAYMENT_DECLINED = 'PAYMENT_DECLINED';
    public const CODE_INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
    public const CODE_INVALID_CARD = 'INVALID_CARD';
    public const CODE_EXPIRED_CARD = 'EXPIRED_CARD';
    public const CODE_INCORRECT_CVC = 'INCORRECT_CVC';
    public const CODE_PROCESSING_ERROR = 'PROCESSING_ERROR';
    public const CODE_RATE_LIMIT = 'RATE_LIMIT';
    public const CODE_3DS_FAILED = '3DS_FAILED';
    public const CODE_SERVER_ERROR = 'SERVER_ERROR';
    public const CODE_NOT_FOUND = 'NOT_FOUND';
    public const CODE_UNAUTHORIZED = 'UNAUTHORIZED';

    /**
     * Create validation error response
     *
     * @param array $validationErrors Array of ['field' => 'fieldName', 'message' => 'error message']
     * @param string $message Overall error message
     * @return array
     */
    public static function validationError(array $validationErrors, string $message = 'Validation failed'): array
    {
        return [
            'success' => false,
            'message' => $message,
            'code' => self::CODE_VALIDATION_ERROR,
            'errors' => $validationErrors,
            'retryable' => false,
        ];
    }

    /**
     * Create payment error response
     *
     * @param string $errorCode Stripe or payment provider error code
     * @param string $message User-friendly error message
     * @param array $details Additional error details
     * @return array
     */
    public static function paymentError(string $errorCode, string $message, array $details = []): array
    {
        $normalizedCode = self::normalizePaymentErrorCode($errorCode);

        return [
            'success' => false,
            'message' => $message,
            'code' => $normalizedCode,
            'providerCode' => $errorCode,
            'details' => $details,
            'retryable' => self::isRetryablePaymentError($normalizedCode),
        ];
    }

    /**
     * Create system error response
     *
     * @param string $message Error message
     * @param \Exception|null $exception Exception object (for logging)
     * @return array
     */
    public static function systemError(string $message = 'An error occurred', ?\Exception $exception = null): array
    {
        return [
            'success' => false,
            'message' => $message,
            'code' => self::CODE_SERVER_ERROR,
            'retryable' => true,
            // Don't expose exception details to frontend in production
            'debug' => $exception && getenv('APP_ENV') === 'development' ? [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ] : null,
        ];
    }

    /**
     * Create not found error response
     *
     * @param string $resource Resource that was not found
     * @return array
     */
    public static function notFoundError(string $resource = 'Resource'): array
    {
        return [
            'success' => false,
            'message' => "$resource not found",
            'code' => self::CODE_NOT_FOUND,
            'retryable' => false,
        ];
    }

    /**
     * Create unauthorized error response
     *
     * @param string $message Error message
     * @return array
     */
    public static function unauthorizedError(string $message = 'Unauthorized access'): array
    {
        return [
            'success' => false,
            'message' => $message,
            'code' => self::CODE_UNAUTHORIZED,
            'retryable' => false,
        ];
    }

    /**
     * Create rate limit error response
     *
     * @param int $retryAfter Seconds until retry is allowed
     * @return array
     */
    public static function rateLimitError(int $retryAfter = 60): array
    {
        return [
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'code' => self::CODE_RATE_LIMIT,
            'retryAfter' => $retryAfter,
            'retryable' => true,
        ];
    }

    /**
     * Normalize Stripe error codes to our standard codes
     *
     * @param string $stripeCode Stripe error code
     * @return string Normalized error code
     */
    private static function normalizePaymentErrorCode(string $stripeCode): string
    {
        $mapping = [
            'card_declined' => self::CODE_PAYMENT_DECLINED,
            'generic_decline' => self::CODE_PAYMENT_DECLINED,
            'insufficient_funds' => self::CODE_INSUFFICIENT_FUNDS,
            'lost_card' => self::CODE_PAYMENT_DECLINED,
            'stolen_card' => self::CODE_PAYMENT_DECLINED,
            'expired_card' => self::CODE_EXPIRED_CARD,
            'incorrect_cvc' => self::CODE_INCORRECT_CVC,
            'incorrect_number' => self::CODE_INVALID_CARD,
            'invalid_number' => self::CODE_INVALID_CARD,
            'invalid_expiry_month' => self::CODE_INVALID_CARD,
            'invalid_expiry_year' => self::CODE_INVALID_CARD,
            'invalid_cvc' => self::CODE_INVALID_CARD,
            'processing_error' => self::CODE_PROCESSING_ERROR,
            'rate_limit' => self::CODE_RATE_LIMIT,
            'authentication_required' => self::CODE_3DS_FAILED,
        ];

        return $mapping[$stripeCode] ?? self::CODE_PROCESSING_ERROR;
    }

    /**
     * Check if payment error is retryable
     *
     * @param string $errorCode Normalized error code
     * @return bool
     */
    private static function isRetryablePaymentError(string $errorCode): bool
    {
        $retryableErrors = [
            self::CODE_PROCESSING_ERROR,
            self::CODE_RATE_LIMIT,
        ];

        return in_array($errorCode, $retryableErrors);
    }

    /**
     * Get user-friendly error message for payment error
     *
     * @param string $errorCode Normalized error code
     * @return string
     */
    public static function getUserFriendlyMessage(string $errorCode): string
    {
        $messages = [
            self::CODE_PAYMENT_DECLINED => 'Your card was declined. Please try a different payment method.',
            self::CODE_INSUFFICIENT_FUNDS => 'Insufficient funds. Please use a different card.',
            self::CODE_INVALID_CARD => 'Invalid card details. Please check and try again.',
            self::CODE_EXPIRED_CARD => 'Your card has expired. Please use a different card.',
            self::CODE_INCORRECT_CVC => 'Incorrect security code. Please check and try again.',
            self::CODE_PROCESSING_ERROR => 'Payment processing error. Please try again.',
            self::CODE_RATE_LIMIT => 'Too many attempts. Please wait a moment and try again.',
            self::CODE_3DS_FAILED => '3D Secure authentication failed. Please try again.',
        ];

        return $messages[$errorCode] ?? 'Payment failed. Please try again.';
    }

    /**
     * Wrap GraphQL mutation response with error handling
     *
     * @param callable $callback Function that returns mutation result
     * @return array Standardized response
     */
    public static function wrapMutation(callable $callback): array
    {
        try {
            return $callback();
        } catch (\InvalidArgumentException $e) {
            return self::validationError([], $e->getMessage());
        } catch (\RuntimeException $e) {
            return self::systemError($e->getMessage(), $e);
        } catch (\Exception $e) {
            return self::systemError('An unexpected error occurred', $e);
        }
    }
}
