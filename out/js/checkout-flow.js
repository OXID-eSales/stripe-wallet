/**
 * CheckoutFlow - Main checkout orchestration
 *
 * Coordinates between address, payment, and review steps
 * Integrates with error handling and abandonment tracking
 */

class CheckoutFlow {
    constructor(checkoutClient, abandonmentTracker, config) {
        this.client = checkoutClient;
        this.tracker = abandonmentTracker;
        this.config = config;
        this.currentStep = 'address';

        this.steps = ['address', 'payment', 'review'];
        this.stepData = {
            address: null,
            payment: null
        };

        this.init();
    }

    /**
     * Initialize checkout flow
     */
    init() {
        this.setupFormHandlers();
        this.setupStepNavigation();
        this.updateProgressIndicator();
    }

    /**
     * Setup form submission handlers
     */
    setupFormHandlers() {
        // Address form
        const addressForm = document.getElementById('address-form');
        if (addressForm) {
            addressForm.addEventListener('submit', (e) => this.handleAddressSubmit(e));
        }

        // Payment form
        const paymentForm = document.getElementById('payment-form');
        if (paymentForm) {
            paymentForm.addEventListener('submit', (e) => this.handlePaymentSubmit(e));
        }

        // Back buttons
        document.getElementById('back-to-address-btn')?.addEventListener('click', () => {
            this.goToStep('address');
        });

        // Edit buttons in review
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const step = e.target.dataset.edit;
                this.goToStep(step);
            });
        });
    }

    /**
     * Handle address form submission
     */
    async handleAddressSubmit(e) {
        e.preventDefault();

        const form = e.target;
        const submitBtn = document.getElementById('address-submit-btn');
        const originalText = submitBtn.innerHTML;

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.innerHTML = this.getLoadingButton('Processing...');

        try {
            // Get form data
            const formData = new FormData(form);
            const addressData = this.extractAddressData(formData);

            // Submit to backend
            const result = await this.client.updateAddress(addressData);

            if (result.updateAddress.success) {
                // Store address data
                this.stepData.address = addressData;

                // Update tracker
                this.tracker.updateState({
                    stage: 'payment',
                    addressCompleted: true,
                    email: addressData.email,
                    billingAddress: addressData.billingAddress
                });

                // Move to payment step
                this.goToStep('payment');
            }
        } catch (error) {
            console.error('Address submission failed:', error);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    /**
     * Handle payment form submission
     */
    async handlePaymentSubmit(e) {
        e.preventDefault();

        const form = e.target;
        const submitBtn = document.getElementById('payment-submit-btn');
        const originalText = submitBtn.innerHTML;

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.innerHTML = this.getLoadingButton('Processing Payment...');

        try {
            // Mark payment as attempted
            this.tracker.updateState({ paymentAttempted: true });

            // Get form data
            const formData = new FormData(form);
            const cardData = this.extractCardData(formData);

            // Submit payment
            const result = await this.client.processPayment(
                cardData,
                Math.round(this.config.cartTotal * 100), // Convert to cents
                this.config.currency,
                {
                    returnUrl: this.config.returnUrl,
                    saveCard: formData.get('saveCard') === 'on'
                }
            );

            const payment = result.processPayment;

            if (payment.success) {
                // Store payment data
                this.stepData.payment = {
                    method: formData.get('paymentMethod'),
                    cardLast4: cardData.card.number.slice(-4)
                };

                if (payment.status === 'REQUIRES_ACTION' && payment.redirectUrl) {
                    // 3D Secure required
                    window.location.href = payment.redirectUrl;
                } else if (payment.status === 'SUCCEEDED') {
                    // Payment succeeded immediately
                    this.tracker.markComplete();
                    this.showSuccess(payment.orderId);
                } else {
                    // Payment pending - move to review
                    this.goToStep('review');
                }
            } else {
                // Error handled by error handler
                this.tracker.trackPaymentFailure(payment.message);
            }
        } catch (error) {
            console.error('Payment submission failed:', error);
            this.tracker.trackPaymentFailure(error.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    /**
     * Place final order
     */
    async placeOrder() {
        const submitBtn = document.getElementById('place-order-btn');
        const originalText = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = this.getLoadingButton('Placing Order...');

        try {
            // Final order placement
            // This would typically just confirm and finalize
            // since payment was already processed

            // For now, show success
            this.tracker.markComplete();
            this.showSuccess('ORDER_' + Date.now());
        } catch (error) {
            console.error('Order placement failed:', error);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    /**
     * Navigate to specific step
     */
    goToStep(stepName) {
        if (!this.steps.includes(stepName)) {
            console.error('Invalid step:', stepName);
            return;
        }

        this.currentStep = stepName;

        // Update tracker
        this.tracker.updateState({ stage: stepName });

        // Hide all sections
        document.querySelectorAll('.checkout-section').forEach(section => {
            section.classList.add('section-disabled');
        });

        // Show target section
        const targetSection = document.getElementById(`${stepName}-section`);
        if (targetSection) {
            targetSection.classList.remove('section-disabled');
            targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Update progress indicator
        this.updateProgressIndicator();
    }

    /**
     * Update progress indicator
     */
    updateProgressIndicator() {
        const progressSteps = document.querySelectorAll('.progress-step');
        const currentIndex = this.steps.indexOf(this.currentStep);

        progressSteps.forEach((step, index) => {
            if (index < currentIndex) {
                step.classList.add('completed');
                step.classList.remove('active');
            } else if (index === currentIndex) {
                step.classList.add('active');
                step.classList.remove('completed');
            } else {
                step.classList.remove('active', 'completed');
            }
        });
    }

    /**
     * Show success page
     */
    showSuccess(orderId) {
        // Hide all checkout sections
        document.querySelectorAll('.checkout-section').forEach(section => {
            section.style.display = 'none';
        });

        // Show success section
        const successSection = document.getElementById('success-section');
        successSection.style.display = 'block';

        // Fill in order details
        document.getElementById('success-order-number').textContent = orderId;
        document.getElementById('success-order-date').textContent = new Date().toLocaleDateString();
        document.getElementById('success-order-total').textContent =
            this.config.cartTotal.toFixed(2) + ' ' + this.config.currency;
        document.getElementById('success-email').textContent = this.stepData.address?.email || '';

        // Update order link
        const viewOrderBtn = document.getElementById('view-order-btn');
        if (viewOrderBtn) {
            viewOrderBtn.href = viewOrderBtn.href.replace('ORDER_ID', orderId);
        }

        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });

        // Clear cart (via AJAX)
        this.clearCart();
    }

    /**
     * Extract address data from form
     */
    extractAddressData(formData) {
        return {
            billingAddress: {
                firstName: formData.get('billingAddress[firstName]'),
                lastName: formData.get('billingAddress[lastName]'),
                email: formData.get('billingAddress[email]'),
                phone: formData.get('billingAddress[phone]'),
                street: formData.get('billingAddress[street]'),
                streetNo: formData.get('billingAddress[streetNo]'),
                city: formData.get('billingAddress[city]'),
                zip: formData.get('billingAddress[zip]'),
                countryCode: formData.get('billingAddress[countryCode]')
            },
            useBillingAsShipping: formData.get('useBillingAsShipping') === 'on',
            shippingAddress: formData.get('useBillingAsShipping') === 'on' ? null : {
                firstName: formData.get('shippingAddress[firstName]'),
                lastName: formData.get('shippingAddress[lastName]'),
                // ... other fields
            }
        };
    }

    /**
     * Extract card data from form
     */
    extractCardData(formData) {
        return {
            card: {
                number: formData.get('cardNumber').replace(/\s/g, ''),
                exp_month: parseInt(formData.get('expMonth')),
                exp_year: parseInt(formData.get('expYear')),
                cvc: formData.get('cvc'),
                name: formData.get('cardholderName')
            }
        };
    }

    /**
     * Get loading button HTML
     */
    getLoadingButton(text) {
        return `${text}<span class="spinner"></span>`;
    }

    /**
     * Setup step navigation
     */
    setupStepNavigation() {
        // Allow clicking on completed steps in progress indicator
        document.querySelectorAll('.progress-step').forEach(step => {
            step.addEventListener('click', () => {
                if (step.classList.contains('completed')) {
                    const stepName = step.dataset.step;
                    this.goToStep(stepName);
                }
            });
        });
    }

    /**
     * Clear cart after successful order
     */
    async clearCart() {
        try {
            await fetch(window.location.origin + '?cl=basket&fnc=clearBasket', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                }
            });
        } catch (error) {
            console.error('Failed to clear cart:', error);
        }
    }
}

// Make available globally
window.CheckoutFlow = CheckoutFlow;
