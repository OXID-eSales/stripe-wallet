/**
 * Stripe Payment Element Integration for OXID eShop 7.0+
 * Injects Payment Element into standard payment page
 */
(function() {
    'use strict';

    const STRIPE_PAYMENT_ID = 'osc_stripe_card';
    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let stripeContainer = null;

    /**
     * Initialize when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        // Check if we have Stripe configuration
        if (!window.stripeConfig || !window.stripeConfig.publishableKey) {
            return;
        }

        // Find the payment method radio button for Stripe
        const stripeRadio = document.querySelector('input[name="paymentid"][value="' + STRIPE_PAYMENT_ID + '"]');
        if (!stripeRadio) {
            return;
        }

        // Create container for Stripe Payment Element
        createStripeContainer(stripeRadio);

        // Listen for payment method changes
        const paymentRadios = document.querySelectorAll('input[name="paymentid"]');
        paymentRadios.forEach(function(radio) {
            radio.addEventListener('change', handlePaymentMethodChange);
        });

        // Check initial state
        handlePaymentMethodChange();

        // Intercept form submission
        interceptFormSubmission();

        // Load Stripe.js
        loadStripeJs();
    });

    /**
     * Create container for Stripe Payment Element
     */
    function createStripeContainer(stripeRadio) {
        // Find the payment method wrapper
        const paymentMethodDiv = stripeRadio.closest('.payment-option, .well, dl');
        if (!paymentMethodDiv) {
            console.error('Could not find payment method container');
            return;
        }

        // Create Stripe container
        stripeContainer = document.createElement('div');
        stripeContainer.id = 'stripe-payment-container';
        stripeContainer.className = 'stripe-payment-wrapper';
        stripeContainer.style.display = 'none';
        stripeContainer.innerHTML = `
            <div class="stripe-payment-element-wrapper">
                <div class="payment-description">
                    <h3>${window.stripeConfig.labels.cardPayment || 'Credit Card Payment'}</h3>
                    <p class="text-muted">${window.stripeConfig.labels.paymentDesc || 'Pay securely with your card'}</p>
                </div>

                <div id="payment-element" class="stripe-payment-element"></div>

                <div id="payment-errors" class="stripe-errors alert alert-danger" style="display: none;" role="alert">
                    <i class="fa fa-exclamation-circle"></i>
                    <span id="payment-error-message"></span>
                </div>

                <div id="payment-loading" class="stripe-loading" style="display: none;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">${window.stripeConfig.labels.processing || 'Processing...'}</span>
                    </div>
                    <p>${window.stripeConfig.labels.processingPayment || 'Processing your payment. Please wait...'}</p>
                </div>

                <div class="stripe-security-info">
                    <p class="text-muted small">
                        <i class="fa fa-lock"></i>
                        ${window.stripeConfig.labels.securePayment || 'Secure payment powered by Stripe'}
                    </p>
                </div>
            </div>
        `;

        // Insert after the payment method selection
        paymentMethodDiv.parentNode.insertBefore(stripeContainer, paymentMethodDiv.nextSibling);
    }

    /**
     * Load Stripe.js dynamically
     */
    function loadStripeJs() {
        // Check if Stripe.js is already loaded
        if (window.Stripe) {
            initializeStripe();
            return;
        }

        // Load Stripe.js
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = initializeStripe;
        script.onerror = function() {
            console.error('Failed to load Stripe.js');
            showError(window.stripeConfig.labels.unexpectedError || 'Failed to load payment system');
        };
        document.head.appendChild(script);
    }

    /**
     * Initialize Stripe instance
     */
    function initializeStripe() {
        if (!window.stripeConfig.publishableKey) {
            console.error('Stripe publishable key not configured');
            return;
        }

        try {
            stripe = Stripe(window.stripeConfig.publishableKey, {
                locale: window.stripeConfig.locale || 'auto'
            });
        } catch (error) {
            console.error('Failed to initialize Stripe:', error);
            showError(window.stripeConfig.labels.configError || 'Payment system configuration error');
        }
    }

    /**
     * Initialize Payment Element if Stripe is selected
     */
    function initializePaymentElement() {
        if (!stripe) {
            console.error('Stripe not initialized');
            return;
        }

        if (!window.stripeConfig.clientSecret) {
            console.error('Client secret not available');
            showError(window.stripeConfig.labels.intentError || 'Payment initialization failed');
            return;
        }

        // Don't reinitialize if already mounted
        if (paymentElement && elements) {
            return;
        }

        try {
            // Appearance configuration
            const appearance = {
                theme: 'stripe',
                variables: {
                    colorPrimary: window.stripeConfig.primaryColor || '#0570de',
                    colorBackground: '#ffffff',
                    colorText: '#30313d',
                    colorDanger: '#df1b41',
                    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                    spacingUnit: '4px',
                    borderRadius: '4px'
                }
            };

            elements = stripe.elements({
                clientSecret: window.stripeConfig.clientSecret,
                appearance: appearance
            });

            paymentElement = elements.create('payment', {
                layout: 'tabs'
            });

            paymentElement.mount('#payment-element');

            // Handle validation errors
            paymentElement.on('change', function(event) {
                if (event.error) {
                    showError(event.error.message);
                } else {
                    hideError();
                }
            });

        } catch (error) {
            console.error('Failed to initialize Payment Element:', error);
            showError(window.stripeConfig.labels.unexpectedError || 'Payment element initialization failed');
        }
    }

    /**
     * Handle payment method change
     */
    function handlePaymentMethodChange() {
        const selectedPayment = document.querySelector('input[name="paymentid"]:checked');

        if (!stripeContainer) {
            return;
        }

        if (selectedPayment && selectedPayment.value === STRIPE_PAYMENT_ID) {
            // Show Stripe container
            stripeContainer.style.display = 'block';

            // Initialize Payment Element if not already done
            if (!paymentElement) {
                initializePaymentElement();
            }
        } else {
            // Hide Stripe container
            stripeContainer.style.display = 'none';
        }
    }

    /**
     * Intercept form submission for Stripe payments
     */
    function interceptFormSubmission() {
        // Find the payment form
        const form = document.querySelector('form[name="payment"]') ||
                     document.querySelector('form[action*="payment"]') ||
                     document.querySelector('.checkoutSteps form');

        if (!form) {
            console.warn('Payment form not found');
            return;
        }

        form.addEventListener('submit', async function(event) {
            const selectedPayment = document.querySelector('input[name="paymentid"]:checked');

            // Only intercept for Stripe payments
            if (!selectedPayment || selectedPayment.value !== STRIPE_PAYMENT_ID) {
                return true; // Let OXID handle other payments
            }

            event.preventDefault();
            event.stopPropagation();

            if (!stripe || !elements) {
                showError(window.stripeConfig.labels.unexpectedError || 'Payment system not initialized');
                return false;
            }

            // Show loading
            const loadingDiv = document.getElementById('payment-loading');
            if (loadingDiv) {
                loadingDiv.style.display = 'block';
            }
            hideError();

            try {
                const { error } = await stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: window.stripeConfig.returnUrl
                    }
                });

                // This only runs if there's an immediate error
                if (error) {
                    showError(error.message);
                    if (loadingDiv) {
                        loadingDiv.style.display = 'none';
                    }
                }
            } catch (err) {
                console.error('Stripe payment error:', err);
                showError(window.stripeConfig.labels.unexpectedError || 'Payment processing failed');
                if (loadingDiv) {
                    loadingDiv.style.display = 'none';
                }
            }

            return false;
        });
    }

    /**
     * Show error message
     */
    function showError(message) {
        const errorDiv = document.getElementById('payment-errors');
        const errorMessage = document.getElementById('payment-error-message');
        if (errorDiv && errorMessage) {
            errorMessage.textContent = message;
            errorDiv.style.display = 'block';
        }
    }

    /**
     * Hide error message
     */
    function hideError() {
        const errorDiv = document.getElementById('payment-errors');
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
    }

})();
