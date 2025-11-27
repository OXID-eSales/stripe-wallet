/**
 * One-Page Checkout Client Example
 *
 * This demonstrates how to integrate with the one-page checkout GraphQL API
 * following the sequence diagram flow.
 */

class OnePageCheckoutClient {
    constructor(graphqlEndpoint, encryptionKey) {
        this.graphqlEndpoint = graphqlEndpoint;
        this.encryptionKey = encryptionKey;
    }

    /**
     * Update customer address
     */
    async updateAddress(addressData) {
        const mutation = `
            mutation UpdateAddress($input: UpdateAddressInput!) {
                updateAddress(input: $input) {
                    success
                    message
                    errors {
                        field
                        message
                    }
                }
            }
        `;

        const variables = {
            input: {
                billingAddress: {
                    firstName: addressData.firstName,
                    lastName: addressData.lastName,
                    street: addressData.street,
                    streetNo: addressData.streetNo,
                    city: addressData.city,
                    zip: addressData.zip,
                    countryCode: addressData.countryCode,
                    phone: addressData.phone,
                    email: addressData.email
                },
                useBillingAsShipping: addressData.useBillingAsShipping ?? true
            }
        };

        return this.executeGraphQL(mutation, variables);
    }

    /**
     * Process payment with encrypted card data
     */
    async processPayment(cardData, amount, currency, options = {}) {
        // Encrypt card data using Web Crypto API
        const encryptedData = await this.encryptCardData(cardData);

        const mutation = `
            mutation ProcessPayment($input: ProcessPaymentInput!) {
                processPayment(input: $input) {
                    success
                    orderId
                    status
                    message
                    redirectUrl
                    clientSecret
                    errors {
                        field
                        message
                    }
                }
            }
        `;

        const variables = {
            input: {
                encryptedData: encryptedData,
                amount: amount, // in cents
                currency: currency,
                saveCard: options.saveCard ?? false,
                returnUrl: options.returnUrl ?? window.location.href
            }
        };

        return this.executeGraphQL(mutation, variables);
    }

    /**
     * Encrypt card data using Web Crypto API (AES-256-GCM)
     */
    async encryptCardData(cardData) {
        try {
            // Import encryption key
            const keyData = await this.base64ToArrayBuffer(this.encryptionKey);
            const key = await crypto.subtle.importKey(
                'raw',
                keyData,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt']
            );

            // Generate random IV
            const iv = crypto.getRandomValues(new Uint8Array(12));

            // Prepare data
            const dataString = JSON.stringify(cardData);
            const encoder = new TextEncoder();
            const data = encoder.encode(dataString);

            // Encrypt
            const encrypted = await crypto.subtle.encrypt(
                {
                    name: 'AES-GCM',
                    iv: iv,
                    tagLength: 128
                },
                key,
                data
            );

            // Extract ciphertext and auth tag
            const ciphertext = new Uint8Array(encrypted.slice(0, -16));
            const authTag = new Uint8Array(encrypted.slice(-16));

            // Build payload
            const payload = {
                iv: this.arrayBufferToBase64(iv),
                authTag: this.arrayBufferToBase64(authTag),
                ciphertext: this.arrayBufferToBase64(ciphertext)
            };

            // Return formatted encrypted data
            return 'ENC:' + btoa(JSON.stringify(payload));
        } catch (error) {
            console.error('Encryption failed:', error);
            throw new Error('Failed to encrypt card data');
        }
    }

    /**
     * Execute GraphQL request
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
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.errors) {
                console.error('GraphQL errors:', result.errors);
                throw new Error('GraphQL request failed');
            }

            return result.data;
        } catch (error) {
            console.error('GraphQL request failed:', error);
            throw error;
        }
    }

    // Helper functions
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    async base64ToArrayBuffer(base64) {
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }
}

// ==========================================
// Example Usage
// ==========================================

// Initialize client
const checkout = new OnePageCheckoutClient(
    '/graphql', // GraphQL endpoint
    'your-base64-encoded-encryption-key' // Must match server key
);

// Example: Update address
async function handleAddressSubmit() {
    try {
        const result = await checkout.updateAddress({
            firstName: 'John',
            lastName: 'Doe',
            street: 'Main Street',
            streetNo: '123',
            city: 'Berlin',
            zip: '10115',
            countryCode: 'DE',
            phone: '+49 30 12345678',
            email: 'john.doe@example.com',
            useBillingAsShipping: true
        });

        if (result.updateAddress.success) {
            console.log('Address updated successfully!');
            // Enable payment section
            document.getElementById('payment-section').style.display = 'block';
        } else {
            console.error('Address update failed:', result.updateAddress.errors);
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

// Example: Process payment
async function handlePaymentSubmit() {
    try {
        const cardData = {
            card: {
                number: '4242424242424242',
                exp_month: 12,
                exp_year: 2025,
                cvc: '123',
                name: 'John Doe'
            }
        };

        const result = await checkout.processPayment(
            cardData,
            2999, // €29.99 in cents
            'EUR',
            {
                saveCard: false,
                returnUrl: window.location.origin + '/checkout/success'
            }
        );

        const payment = result.processPayment;

        if (payment.success) {
            if (payment.status === 'REQUIRES_ACTION' && payment.redirectUrl) {
                // Redirect for 3D Secure
                window.location.href = payment.redirectUrl;
            } else if (payment.status === 'SUCCEEDED') {
                // Payment succeeded
                console.log('Payment successful! Order ID:', payment.orderId);
                showConfirmation(payment.orderId);
            } else {
                // Payment pending
                console.log('Payment is being processed...');
                pollPaymentStatus(payment.orderId);
            }
        } else {
            console.error('Payment failed:', payment.message);
            showError(payment.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('Payment processing failed. Please try again.');
    }
}

// UI helper functions
function showConfirmation(orderId) {
    document.getElementById('payment-form').style.display = 'none';
    document.getElementById('confirmation').style.display = 'block';
    document.getElementById('order-id').textContent = orderId;
}

function showError(message) {
    const errorDiv = document.getElementById('error-message');
    errorDiv.textContent = message;
    errorDiv.style.display = 'block';
}

function pollPaymentStatus(orderId) {
    // Implement polling or webhook to check payment status
    console.log('Polling payment status for order:', orderId);
}

// ==========================================
// HTML Example
// ==========================================
/*
<!DOCTYPE html>
<html>
<head>
    <title>One-Page Checkout</title>
</head>
<body>
    <div id="checkout-page">
        <!-- Address Section -->
        <section id="address-section">
            <h2>Shipping Address</h2>
            <form id="address-form">
                <input type="text" name="firstName" placeholder="First Name" required>
                <input type="text" name="lastName" placeholder="Last Name" required>
                <input type="text" name="street" placeholder="Street" required>
                <input type="text" name="city" placeholder="City" required>
                <input type="text" name="zip" placeholder="ZIP Code" required>
                <input type="email" name="email" placeholder="Email" required>
                <button type="submit">Continue to Payment</button>
            </form>
        </section>

        <!-- Payment Section (hidden initially) -->
        <section id="payment-section" style="display: none;">
            <h2>Payment Details</h2>
            <form id="payment-form">
                <input type="text" name="cardNumber" placeholder="Card Number" required>
                <input type="text" name="expMonth" placeholder="MM" required>
                <input type="text" name="expYear" placeholder="YYYY" required>
                <input type="text" name="cvc" placeholder="CVC" required>
                <button type="submit">Pay €29.99</button>
            </form>
        </section>

        <!-- Confirmation Section -->
        <section id="confirmation" style="display: none;">
            <h2>Order Confirmed!</h2>
            <p>Order ID: <span id="order-id"></span></p>
        </section>

        <!-- Error Message -->
        <div id="error-message" style="display: none; color: red;"></div>
    </div>

    <script src="one-page-checkout-client.js"></script>
</body>
</html>
*/
