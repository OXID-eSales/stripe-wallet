/**
 * Error Handling System for One-Page Checkout
 *
 * Displays backend errors in real-time without page reloads:
 * - Validation errors (inline + toast)
 * - Payment errors
 * - Network errors
 * - Server errors
 * - Retry mechanisms
 */

class CheckoutErrorHandler {
    constructor(options = {}) {
        this.options = {
            toastDuration: options.toastDuration || 5000,
            enableRetry: options.enableRetry !== false,
            maxRetries: options.maxRetries || 3,
            retryDelay: options.retryDelay || 1000,
            ...options
        };

        this.retryCount = new Map();
        this.initializeUI();
    }

    /**
     * Initialize error UI components
     */
    initializeUI() {
        // Create toast container if not exists
        if (!document.getElementById('checkout-toast-container')) {
            const container = document.createElement('div');
            container.id = 'checkout-toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        // Inject CSS
        this.injectStyles();
    }

    /**
     * Handle GraphQL errors
     */
    handleGraphQLError(error, context = {}) {
        console.error('GraphQL Error:', error);

        // Check if it's a GraphQL error response
        if (error.errors && Array.isArray(error.errors)) {
            error.errors.forEach(gqlError => {
                this.showError({
                    type: 'graphql',
                    message: gqlError.message,
                    code: gqlError.extensions?.code,
                    context: context
                });
            });
            return;
        }

        // Check if it's a validation error response
        if (error.data && error.data.errors) {
            this.handleValidationErrors(error.data.errors, context);
            return;
        }

        // Generic GraphQL error
        this.showError({
            type: 'graphql',
            message: error.message || 'An error occurred processing your request',
            context: context
        });
    }

    /**
     * Handle validation errors (field-specific)
     */
    handleValidationErrors(errors, context = {}) {
        if (!Array.isArray(errors)) {
            return;
        }

        // Clear existing validation errors
        this.clearValidationErrors();

        // Show each validation error
        errors.forEach(error => {
            // Show inline error near field
            this.showInlineError(error.field, error.message);

            // Also show toast for first error
            if (errors.indexOf(error) === 0) {
                this.showToast({
                    type: 'error',
                    title: 'Validation Error',
                    message: error.message,
                    icon: '⚠️'
                });
            }
        });
    }

    /**
     * Handle payment errors
     */
    handlePaymentError(error, context = {}) {
        console.error('Payment Error:', error);

        const errorMessages = {
            'card_declined': 'Your card was declined. Please try a different payment method.',
            'insufficient_funds': 'Insufficient funds. Please use a different card.',
            'invalid_card': 'Invalid card details. Please check and try again.',
            'expired_card': 'Your card has expired. Please use a different card.',
            'incorrect_cvc': 'Incorrect security code. Please check and try again.',
            'processing_error': 'Payment processing error. Please try again.',
            'rate_limit': 'Too many attempts. Please wait a moment and try again.',
            '3ds_failed': '3D Secure authentication failed. Please try again.'
        };

        const message = errorMessages[error.code] || error.message || 'Payment failed. Please try again.';

        this.showError({
            type: 'payment',
            message: message,
            code: error.code,
            context: context,
            retryable: this.isRetryableError(error.code)
        });

        // Show detailed error in console for debugging
        if (error.details) {
            console.error('Payment Error Details:', error.details);
        }
    }

    /**
     * Handle network errors
     */
    handleNetworkError(error, context = {}) {
        console.error('Network Error:', error);

        let message = 'Network error. Please check your connection.';

        if (error.message === 'Failed to fetch') {
            message = 'Unable to connect to server. Please check your internet connection.';
        } else if (error.message.includes('timeout')) {
            message = 'Request timed out. Please try again.';
        }

        this.showError({
            type: 'network',
            message: message,
            context: context,
            retryable: true
        });
    }

    /**
     * Handle server errors (5xx)
     */
    handleServerError(error, context = {}) {
        console.error('Server Error:', error);

        this.showError({
            type: 'server',
            message: 'Server error. Our team has been notified. Please try again later.',
            code: error.status,
            context: context,
            retryable: error.status === 503 // Service unavailable
        });
    }

    /**
     * Show generic error
     */
    showError(error) {
        const { type, message, code, context, retryable } = error;

        // Show toast notification
        this.showToast({
            type: 'error',
            title: this.getErrorTitle(type),
            message: message,
            icon: '❌',
            retryable: retryable && this.options.enableRetry,
            onRetry: retryable ? () => this.handleRetry(context) : null
        });

        // Log to analytics
        this.logError(error);
    }

    /**
     * Show inline error near form field
     */
    showInlineError(fieldName, message) {
        const field = document.querySelector(`[name="${fieldName}"]`);

        if (!field) {
            console.warn(`Field not found: ${fieldName}`);
            return;
        }

        // Add error class to field
        field.classList.add('error');

        // Create error message element
        const errorElement = document.createElement('div');
        errorElement.className = 'field-error';
        errorElement.textContent = message;
        errorElement.setAttribute('data-field', fieldName);

        // Insert after field
        field.parentNode.insertBefore(errorElement, field.nextSibling);

        // Remove error on input
        field.addEventListener('input', () => {
            this.clearInlineError(fieldName);
        }, { once: true });
    }

    /**
     * Clear inline error for specific field
     */
    clearInlineError(fieldName) {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.classList.remove('error');
        }

        const errorElement = document.querySelector(`.field-error[data-field="${fieldName}"]`);
        if (errorElement) {
            errorElement.remove();
        }
    }

    /**
     * Clear all validation errors
     */
    clearValidationErrors() {
        document.querySelectorAll('.error').forEach(el => {
            el.classList.remove('error');
        });

        document.querySelectorAll('.field-error').forEach(el => {
            el.remove();
        });
    }

    /**
     * Show toast notification
     */
    showToast(toast) {
        const { type, title, message, icon, duration, retryable, onRetry } = toast;

        const toastElement = document.createElement('div');
        toastElement.className = `toast toast-${type}`;

        toastElement.innerHTML = `
            <div class="toast-content">
                <div class="toast-icon">${icon || this.getDefaultIcon(type)}</div>
                <div class="toast-body">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" aria-label="Close">&times;</button>
            </div>
            ${retryable ? `
                <div class="toast-actions">
                    <button class="toast-retry-btn">Retry</button>
                    <button class="toast-cancel-btn">Cancel</button>
                </div>
            ` : ''}
        `;

        const container = document.getElementById('checkout-toast-container');
        container.appendChild(toastElement);

        // Close button
        const closeBtn = toastElement.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => {
            this.closeToast(toastElement);
        });

        // Retry button
        if (retryable && onRetry) {
            const retryBtn = toastElement.querySelector('.toast-retry-btn');
            const cancelBtn = toastElement.querySelector('.toast-cancel-btn');

            retryBtn.addEventListener('click', () => {
                this.closeToast(toastElement);
                onRetry();
            });

            cancelBtn.addEventListener('click', () => {
                this.closeToast(toastElement);
            });
        }

        // Auto-close (unless retryable)
        if (!retryable) {
            setTimeout(() => {
                this.closeToast(toastElement);
            }, duration || this.options.toastDuration);
        }

        // Animate in
        setTimeout(() => {
            toastElement.classList.add('show');
        }, 10);
    }

    /**
     * Close toast notification
     */
    closeToast(toastElement) {
        toastElement.classList.remove('show');
        setTimeout(() => {
            toastElement.remove();
        }, 300);
    }

    /**
     * Handle retry
     */
    handleRetry(context) {
        const key = context.operation || 'default';
        const currentRetries = this.retryCount.get(key) || 0;

        if (currentRetries >= this.options.maxRetries) {
            this.showToast({
                type: 'error',
                title: 'Maximum Retries Reached',
                message: 'Please try again later or contact support.',
                icon: '⚠️'
            });
            this.retryCount.delete(key);
            return;
        }

        this.retryCount.set(key, currentRetries + 1);

        // Show loading state
        this.showToast({
            type: 'info',
            title: 'Retrying...',
            message: `Attempt ${currentRetries + 2} of ${this.options.maxRetries + 1}`,
            icon: '🔄',
            duration: this.options.retryDelay
        });

        // Retry after delay
        setTimeout(() => {
            if (context.retryCallback) {
                context.retryCallback();
            }
        }, this.options.retryDelay);
    }

    /**
     * Check if error is retryable
     */
    isRetryableError(code) {
        const retryableCodes = [
            'processing_error',
            'rate_limit',
            'network_error',
            'timeout',
            'service_unavailable'
        ];

        return retryableCodes.includes(code);
    }

    /**
     * Get error title based on type
     */
    getErrorTitle(type) {
        const titles = {
            'graphql': 'Request Error',
            'validation': 'Validation Error',
            'payment': 'Payment Error',
            'network': 'Connection Error',
            'server': 'Server Error'
        };

        return titles[type] || 'Error';
    }

    /**
     * Get default icon for toast type
     */
    getDefaultIcon(type) {
        const icons = {
            'error': '❌',
            'warning': '⚠️',
            'success': '✅',
            'info': 'ℹ️'
        };

        return icons[type] || 'ℹ️';
    }

    /**
     * Log error to analytics
     */
    logError(error) {
        // Send to analytics service
        if (typeof gtag !== 'undefined') {
            gtag('event', 'checkout_error', {
                event_category: 'Checkout',
                event_label: error.type,
                error_code: error.code,
                error_message: error.message
            });
        }

        // Log to console in development
        if (window.location.hostname === 'localhost') {
            console.group('Checkout Error');
            console.error('Type:', error.type);
            console.error('Message:', error.message);
            console.error('Code:', error.code);
            console.error('Context:', error.context);
            console.groupEnd();
        }
    }

    /**
     * Inject CSS styles
     */
    injectStyles() {
        if (document.getElementById('checkout-error-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'checkout-error-styles';
        style.textContent = `
            /* Toast Container */
            .toast-container {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                max-width: 400px;
            }

            /* Toast */
            .toast {
                background: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                margin-bottom: 10px;
                opacity: 0;
                transform: translateX(400px);
                transition: all 0.3s ease-out;
                overflow: hidden;
            }

            .toast.show {
                opacity: 1;
                transform: translateX(0);
            }

            /* Toast Types */
            .toast-error {
                border-left: 4px solid #dc3545;
            }

            .toast-warning {
                border-left: 4px solid #ffc107;
            }

            .toast-success {
                border-left: 4px solid #28a745;
            }

            .toast-info {
                border-left: 4px solid #17a2b8;
            }

            /* Toast Content */
            .toast-content {
                display: flex;
                align-items: flex-start;
                padding: 16px;
            }

            .toast-icon {
                font-size: 24px;
                margin-right: 12px;
                flex-shrink: 0;
            }

            .toast-body {
                flex: 1;
                min-width: 0;
            }

            .toast-title {
                font-weight: 600;
                font-size: 14px;
                margin-bottom: 4px;
                color: #333;
            }

            .toast-message {
                font-size: 13px;
                color: #666;
                line-height: 1.4;
            }

            .toast-close {
                background: none;
                border: none;
                font-size: 24px;
                color: #999;
                cursor: pointer;
                padding: 0;
                margin-left: 12px;
                line-height: 1;
            }

            .toast-close:hover {
                color: #333;
            }

            /* Toast Actions */
            .toast-actions {
                display: flex;
                gap: 8px;
                padding: 0 16px 16px;
                justify-content: flex-end;
            }

            .toast-retry-btn,
            .toast-cancel-btn {
                padding: 6px 16px;
                border-radius: 4px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                border: 1px solid #ddd;
                background: white;
                transition: all 0.2s;
            }

            .toast-retry-btn {
                background: #007bff;
                color: white;
                border-color: #007bff;
            }

            .toast-retry-btn:hover {
                background: #0056b3;
                border-color: #0056b3;
            }

            .toast-cancel-btn:hover {
                background: #f8f9fa;
            }

            /* Field Errors */
            .field-error {
                color: #dc3545;
                font-size: 12px;
                margin-top: 4px;
                display: block;
            }

            input.error,
            select.error,
            textarea.error {
                border-color: #dc3545 !important;
                background-color: #fff5f5;
            }

            /* Mobile Responsiveness */
            @media (max-width: 768px) {
                .toast-container {
                    top: 10px;
                    right: 10px;
                    left: 10px;
                    max-width: none;
                }

                .toast {
                    transform: translateY(-100px);
                }

                .toast.show {
                    transform: translateY(0);
                }
            }
        `;

        document.head.appendChild(style);
    }
}

// ==========================================
// Integration with OnePageCheckoutClient
// ==========================================

/**
 * Enhanced OnePageCheckoutClient with error handling
 */
class EnhancedCheckoutClient extends OnePageCheckoutClient {
    constructor(graphqlEndpoint, encryptionKey, options = {}) {
        super(graphqlEndpoint, encryptionKey);
        this.errorHandler = new CheckoutErrorHandler(options);
    }

    /**
     * Override executeGraphQL with error handling
     */
    async executeGraphQL(query, variables) {
        try {
            const response = await fetch(this.graphqlEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    query: query,
                    variables: variables
                })
            });

            if (!response.ok) {
                if (response.status >= 500) {
                    throw { type: 'server', status: response.status };
                }
                throw { type: 'http', status: response.status };
            }

            const result = await response.json();

            // Check for GraphQL errors
            if (result.errors) {
                this.errorHandler.handleGraphQLError(result, {
                    operation: this.getOperationName(query),
                    variables: variables
                });
                throw result;
            }

            // Check for validation errors in data
            const operationResult = Object.values(result.data)[0];
            if (operationResult && operationResult.errors && operationResult.errors.length > 0) {
                this.errorHandler.handleValidationErrors(operationResult.errors, {
                    operation: this.getOperationName(query)
                });
            }

            return result.data;
        } catch (error) {
            // Network error
            if (error.name === 'TypeError' || error.message === 'Failed to fetch') {
                this.errorHandler.handleNetworkError(error, {
                    operation: this.getOperationName(query),
                    retryCallback: () => this.executeGraphQL(query, variables)
                });
                throw error;
            }

            // Server error
            if (error.type === 'server') {
                this.errorHandler.handleServerError(error, {
                    operation: this.getOperationName(query)
                });
                throw error;
            }

            // Re-throw if already handled
            throw error;
        }
    }

    /**
     * Get operation name from query
     */
    getOperationName(query) {
        const match = query.match(/mutation\s+(\w+)|query\s+(\w+)/);
        return match ? (match[1] || match[2]) : 'unknown';
    }

    /**
     * Enhanced processPayment with payment error handling
     */
    async processPayment(cardData, amount, currency, options = {}) {
        try {
            const result = await super.processPayment(cardData, amount, currency, options);

            if (!result.processPayment.success) {
                // Handle payment-specific error
                this.errorHandler.handlePaymentError({
                    code: this.extractErrorCode(result.processPayment.message),
                    message: result.processPayment.message
                }, {
                    operation: 'processPayment',
                    amount: amount,
                    currency: currency
                });
            }

            return result;
        } catch (error) {
            // Already handled by executeGraphQL
            throw error;
        }
    }

    /**
     * Extract error code from message
     */
    extractErrorCode(message) {
        // Try to extract Stripe error code
        const codeMatch = message.match(/code:\s*(\w+)/);
        if (codeMatch) {
            return codeMatch[1];
        }

        // Check for common keywords
        if (message.includes('declined')) return 'card_declined';
        if (message.includes('insufficient')) return 'insufficient_funds';
        if (message.includes('expired')) return 'expired_card';
        if (message.includes('invalid')) return 'invalid_card';

        return 'processing_error';
    }
}

// ==========================================
// Example Usage
// ==========================================

const checkout = new EnhancedCheckoutClient('/graphql', 'encryption-key', {
    toastDuration: 5000,
    enableRetry: true,
    maxRetries: 3,
    retryDelay: 1000
});

// Handle address submission
async function handleAddressSubmit(e) {
    e.preventDefault();

    const formData = new FormData(e.target);
    const addressData = {
        firstName: formData.get('firstName'),
        lastName: formData.get('lastName'),
        street: formData.get('street'),
        city: formData.get('city'),
        zip: formData.get('zip'),
        countryCode: formData.get('countryCode'),
        email: formData.get('email')
    };

    try {
        const result = await checkout.updateAddress(addressData);

        if (result.updateAddress.success) {
            // Success - proceed to payment
            showPaymentSection();
        }
    } catch (error) {
        // Error already displayed by error handler
        console.error('Address update failed:', error);
    }
}

// Handle payment submission
async function handlePaymentSubmit(e) {
    e.preventDefault();

    const formData = new FormData(e.target);
    const cardData = {
        card: {
            number: formData.get('cardNumber'),
            exp_month: parseInt(formData.get('expMonth')),
            exp_year: parseInt(formData.get('expYear')),
            cvc: formData.get('cvc'),
            name: formData.get('cardholderName')
        }
    };

    try {
        const result = await checkout.processPayment(
            cardData,
            2999, // €29.99
            'EUR',
            { returnUrl: window.location.origin + '/checkout/success' }
        );

        const payment = result.processPayment;

        if (payment.success) {
            if (payment.status === 'REQUIRES_ACTION') {
                window.location.href = payment.redirectUrl;
            } else if (payment.status === 'SUCCEEDED') {
                showSuccessPage(payment.orderId);
            }
        }
    } catch (error) {
        // Error already displayed by error handler
        console.error('Payment failed:', error);
    }
}
