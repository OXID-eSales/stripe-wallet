/**
 * Checkout Abandonment Tracking Module
 *
 * Automatically tracks when customers abandon the checkout process.
 * Integrates with OnePageCheckoutClient to send abandonment events to backend.
 */

class CheckoutAbandonmentTracker {
    constructor(checkoutClient, options = {}) {
        this.client = checkoutClient;
        this.sessionId = this.generateSessionId();
        this.checkoutStartTime = Date.now();
        this.currentStage = 'address';
        this.addressCompleted = false;
        this.paymentAttempted = false;
        this.cartItems = [];
        this.cartTotal = 0;
        this.currency = 'EUR';
        this.email = null;
        this.billingAddress = null;
        this.contractId = null;

        // Configuration
        this.config = {
            timeoutMinutes: options.timeoutMinutes || 15,
            trackNavigation: options.trackNavigation !== false,
            trackPageUnload: options.trackPageUnload !== false,
            trackPaymentFailure: options.trackPaymentFailure !== false,
            ...options
        };

        // Initialize tracking
        this.initializeTracking();
    }

    /**
     * Initialize all tracking mechanisms
     */
    initializeTracking() {
        // Track page navigation away
        if (this.config.trackNavigation) {
            this.trackNavigation();
        }

        // Track browser close / tab close
        if (this.config.trackPageUnload) {
            this.trackPageUnload();
        }

        // Track inactivity timeout
        this.startTimeoutTracking();

        // Track visibility changes
        this.trackVisibilityChange();
    }

    /**
     * Track navigation away from checkout
     */
    trackNavigation() {
        // Monitor navigation via History API
        const originalPushState = history.pushState;
        const originalReplaceState = history.replaceState;

        const self = this;

        history.pushState = function(...args) {
            const result = originalPushState.apply(this, args);
            self.handleNavigation('NAVIGATION');
            return result;
        };

        history.replaceState = function(...args) {
            const result = originalReplaceState.apply(this, args);
            self.handleNavigation('NAVIGATION');
            return result;
        };

        // Monitor back button
        window.addEventListener('popstate', () => {
            this.handleNavigation('NAVIGATION');
        });

        // Monitor link clicks
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (link && link.href && !link.href.includes('/checkout')) {
                this.handleNavigation('NAVIGATION');
            }
        });
    }

    /**
     * Track page unload (browser/tab close)
     */
    trackPageUnload() {
        window.addEventListener('beforeunload', (e) => {
            // Use sendBeacon for reliable tracking during page unload
            this.sendAbandonmentBeacon('NAVIGATION');
        });

        // Fallback for pagehide event (mobile browsers)
        window.addEventListener('pagehide', () => {
            this.sendAbandonmentBeacon('NAVIGATION');
        });
    }

    /**
     * Track inactivity timeout
     */
    startTimeoutTracking() {
        const timeoutMs = this.config.timeoutMinutes * 60 * 1000;

        // Reset timeout on user activity
        const resetTimeout = () => {
            clearTimeout(this.timeoutId);
            this.timeoutId = setTimeout(() => {
                this.trackAbandonment('TIMEOUT');
            }, timeoutMs);
        };

        // Track various user activities
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetTimeout, true);
        });

        // Start initial timeout
        resetTimeout();
    }

    /**
     * Track page visibility changes
     */
    trackVisibilityChange() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // User switched tab/minimized - potential abandonment
                this.lastHiddenTime = Date.now();
            } else {
                // User returned
                if (this.lastHiddenTime) {
                    const timeAway = Date.now() - this.lastHiddenTime;
                    // If away for more than 5 minutes, consider it abandonment
                    if (timeAway > 5 * 60 * 1000) {
                        this.trackAbandonment('TIMEOUT');
                    }
                }
            }
        });
    }

    /**
     * Handle navigation event
     */
    handleNavigation(reason) {
        // Only track if still in checkout (not after completion)
        if (!this.isCheckoutComplete) {
            this.trackAbandonment(reason);
        }
    }

    /**
     * Update checkout state
     */
    updateState(updates) {
        if (updates.stage) this.currentStage = updates.stage;
        if (updates.addressCompleted !== undefined) this.addressCompleted = updates.addressCompleted;
        if (updates.paymentAttempted !== undefined) this.paymentAttempted = updates.paymentAttempted;
        if (updates.cartItems) this.cartItems = updates.cartItems;
        if (updates.cartTotal !== undefined) this.cartTotal = updates.cartTotal;
        if (updates.currency) this.currency = updates.currency;
        if (updates.email) this.email = updates.email;
        if (updates.billingAddress) this.billingAddress = updates.billingAddress;
        if (updates.contractId) this.contractId = updates.contractId;
    }

    /**
     * Mark checkout as complete (stop tracking)
     */
    markComplete() {
        this.isCheckoutComplete = true;
        clearTimeout(this.timeoutId);
    }

    /**
     * Track payment failure
     */
    trackPaymentFailure(error) {
        if (this.config.trackPaymentFailure) {
            this.paymentAttempted = true;
            this.trackAbandonment('PAYMENT_FAILED', {
                error: error
            });
        }
    }

    /**
     * Track user cancellation
     */
    trackUserCancellation() {
        this.trackAbandonment('USER_CANCELLED');
    }

    /**
     * Send abandonment event to backend
     */
    async trackAbandonment(reason, metadata = {}) {
        // Prevent duplicate tracking
        if (this.abandonmentTracked) {
            return;
        }
        this.abandonmentTracked = true;

        const checkoutState = this.buildCheckoutState();

        try {
            await this.client.abandonCheckout(
                this.sessionId,
                reason,
                checkoutState,
                this.contractId,
                metadata
            );

            console.log('Checkout abandonment tracked:', reason);
        } catch (error) {
            console.error('Failed to track abandonment:', error);
        }
    }

    /**
     * Send abandonment using navigator.sendBeacon (for page unload)
     */
    sendAbandonmentBeacon(reason) {
        if (this.abandonmentTracked || this.isCheckoutComplete) {
            return;
        }
        this.abandonmentTracked = true;

        const checkoutState = this.buildCheckoutState();
        const mutation = `
            mutation AbandonCheckout($input: AbandonCheckoutInput!) {
                abandonCheckout(input: $input) {
                    success
                }
            }
        `;

        const payload = JSON.stringify({
            query: mutation,
            variables: {
                input: {
                    sessionId: this.sessionId,
                    reason: reason,
                    checkoutState: checkoutState,
                    contractId: this.contractId
                }
            }
        });

        // Use sendBeacon for reliable delivery during page unload
        const blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon(this.client.graphqlEndpoint, blob);
    }

    /**
     * Build current checkout state
     */
    buildCheckoutState() {
        return {
            currentStage: this.currentStage,
            addressCompleted: this.addressCompleted,
            paymentAttempted: this.paymentAttempted,
            cartItems: this.cartItems.map(item => ({
                productId: item.productId || item.id,
                productName: item.productName || item.name,
                quantity: item.quantity,
                price: item.price
            })),
            cartTotal: this.cartTotal,
            currency: this.currency,
            timeSpent: Math.floor((Date.now() - this.checkoutStartTime) / 1000),
            email: this.email,
            billingAddress: this.billingAddress
        };
    }

    /**
     * Generate unique session ID
     */
    generateSessionId() {
        return 'checkout_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
}

// ==========================================
// Extension to OnePageCheckoutClient
// ==========================================

// Add abandonCheckout method to OnePageCheckoutClient prototype
if (typeof OnePageCheckoutClient !== 'undefined') {
    OnePageCheckoutClient.prototype.abandonCheckout = async function(
        sessionId,
        reason,
        checkoutState,
        contractId = null,
        metadata = null
    ) {
        const mutation = `
            mutation AbandonCheckout($input: AbandonCheckoutInput!) {
                abandonCheckout(input: $input) {
                    success
                    message
                }
            }
        `;

        const variables = {
            input: {
                sessionId: sessionId,
                reason: reason,
                checkoutState: checkoutState,
                contractId: contractId,
                metadata: metadata
            }
        };

        return this.executeGraphQL(mutation, variables);
    };
}

// ==========================================
// Example Usage
// ==========================================

// Initialize checkout with abandonment tracking
const checkout = new OnePageCheckoutClient('/graphql', 'encryption-key');
const abandonmentTracker = new CheckoutAbandonmentTracker(checkout, {
    timeoutMinutes: 15,
    trackNavigation: true,
    trackPageUnload: true,
    trackPaymentFailure: true
});

// Set initial cart data
abandonmentTracker.updateState({
    cartItems: [
        { productId: 'P123', productName: 'Product A', quantity: 2, price: 29.99 },
        { productId: 'P456', productName: 'Product B', quantity: 1, price: 49.99 }
    ],
    cartTotal: 109.97,
    currency: 'EUR',
    stage: 'address'
});

// When address is completed
async function handleAddressSubmit(addressData) {
    const result = await checkout.updateAddress(addressData);

    if (result.updateAddress.success) {
        // Update tracker state
        abandonmentTracker.updateState({
            stage: 'payment',
            addressCompleted: true,
            email: addressData.email,
            billingAddress: addressData.billingAddress
        });
    }
}

// When payment is attempted
async function handlePaymentSubmit(cardData) {
    abandonmentTracker.updateState({
        paymentAttempted: true
    });

    try {
        const result = await checkout.processPayment(cardData, 10997, 'EUR');
        const payment = result.processPayment;

        if (payment.success && payment.status === 'SUCCEEDED') {
            // Mark checkout as complete (stop tracking)
            abandonmentTracker.markComplete();
        } else if (!payment.success) {
            // Track payment failure
            abandonmentTracker.trackPaymentFailure(payment.message);
        }

        // Store contract ID
        if (payment.contractId) {
            abandonmentTracker.updateState({
                contractId: payment.contractId
            });
        }
    } catch (error) {
        // Track payment failure
        abandonmentTracker.trackPaymentFailure(error.message);
    }
}

// If user clicks "Cancel" button
function handleCancelButton() {
    abandonmentTracker.trackUserCancellation();
    window.location.href = '/cart';
}

// ==========================================
// Analytics Integration Example
// ==========================================

// Send abandonment data to Google Analytics
abandonmentTracker.onAbandonment = (reason, state) => {
    if (typeof gtag !== 'undefined') {
        gtag('event', 'checkout_abandonment', {
            'event_category': 'Checkout',
            'event_label': reason,
            'value': state.cartTotal,
            'checkout_stage': state.currentStage,
            'address_completed': state.addressCompleted,
            'payment_attempted': state.paymentAttempted
        });
    }
};
