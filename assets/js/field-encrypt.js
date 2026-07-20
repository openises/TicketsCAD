/**
 * NewUI v4.0 - Client-side RSA Field Encryption
 *
 * Encrypts sensitive form fields using the Web Crypto API (RSA-OAEP)
 * before submission, so data is not sent in cleartext over HTTP.
 *
 * Algorithm: RSA-OAEP with SHA-1 hash, 2048-bit key
 * Note: SHA-1 is used for OAEP padding (not for data hashing) because
 * PHP's openssl_private_decrypt with OPENSSL_PKCS1_OAEP_PADDING only
 * supports SHA-1 for the OAEP hash. This is standard and secure for
 * OAEP padding — SHA-1's collision weaknesses do not affect OAEP.
 *
 * Usage:
 *   - Add data-encrypt="true" to any <form>
 *   - Add data-sensitive="true" to fields that should be encrypted
 *   - Call FieldEncrypt.init(publicKeyPem) then FieldEncrypt.autoProtect()
 *
 * ES5 only — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var publicCryptoKey = null;
    var initialized = false;
    var initFailed = false;

    /**
     * Convert a PEM public key string to an ArrayBuffer (DER).
     */
    function pemToArrayBuffer(pem) {
        var lines = pem.replace(/-----BEGIN PUBLIC KEY-----/, '')
                       .replace(/-----END PUBLIC KEY-----/, '')
                       .replace(/\s+/g, '');
        var binary = atob(lines);
        var buffer = new ArrayBuffer(binary.length);
        var view = new Uint8Array(buffer);
        for (var i = 0; i < binary.length; i++) {
            view[i] = binary.charCodeAt(i);
        }
        return buffer;
    }

    /**
     * Generate a random hex string of the given byte length.
     */
    function randomHex(byteLength) {
        var arr = new Uint8Array(byteLength);
        crypto.getRandomValues(arr);
        var hex = '';
        for (var i = 0; i < arr.length; i++) {
            var h = arr[i].toString(16);
            if (h.length < 2) h = '0' + h;
            hex += h;
        }
        return hex;
    }

    /**
     * Convert a string to a Uint8Array (UTF-8).
     */
    function stringToBytes(str) {
        if (typeof TextEncoder !== 'undefined') {
            return new TextEncoder().encode(str);
        }
        // Fallback for older browsers
        var bytes = [];
        for (var i = 0; i < str.length; i++) {
            var c = str.charCodeAt(i);
            if (c < 128) {
                bytes.push(c);
            } else if (c < 2048) {
                bytes.push(192 | (c >> 6));
                bytes.push(128 | (c & 63));
            } else {
                bytes.push(224 | (c >> 12));
                bytes.push(128 | ((c >> 6) & 63));
                bytes.push(128 | (c & 63));
            }
        }
        return new Uint8Array(bytes);
    }

    /**
     * Convert an ArrayBuffer to a base64 string.
     */
    function arrayBufferToBase64(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    /**
     * Check if Web Crypto API is available.
     */
    function hasCryptoSupport() {
        return (typeof crypto !== 'undefined' &&
                typeof crypto.subtle !== 'undefined' &&
                typeof crypto.subtle.importKey === 'function' &&
                typeof crypto.subtle.encrypt === 'function');
    }

    /**
     * Show a warning banner when encryption is unavailable.
     */
    function showCryptoWarning() {
        var existing = document.getElementById('fe-crypto-warning');
        if (existing) return;

        var div = document.createElement('div');
        div.id = 'fe-crypto-warning';
        div.className = 'alert alert-warning py-2 mb-2 small';
        div.setAttribute('role', 'alert');
        div.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
            '<strong>Warning:</strong> Your browser does not support encryption. ' +
            'Sensitive fields will be sent without encryption. ' +
            'Use a modern browser or enable HTTPS for security.';

        var body = document.body;
        if (body.firstChild) {
            body.insertBefore(div, body.firstChild);
        } else {
            body.appendChild(div);
        }
    }

    window.FieldEncrypt = {
        /**
         * Import the RSA public key for encryption.
         *
         * @param {string} publicKeyPem  PEM-encoded RSA public key
         * @return {Promise} Resolves when key is ready
         */
        init: function (publicKeyPem) {
            if (!hasCryptoSupport()) {
                initFailed = true;
                showCryptoWarning();
                // Return a resolved promise so callers don't break
                return Promise.resolve();
            }

            var keyData = pemToArrayBuffer(publicKeyPem);

            return crypto.subtle.importKey(
                'spki',
                keyData,
                {
                    name: 'RSA-OAEP',
                    hash: { name: 'SHA-1' }
                },
                false,
                ['encrypt']
            ).then(function (key) {
                publicCryptoKey = key;
                initialized = true;
            }).catch(function (err) {
                initFailed = true;
                showCryptoWarning();
                if (typeof console !== 'undefined') {
                    console.error('FieldEncrypt: Key import failed:', err);
                }
            });
        },

        /**
         * Encrypt a plaintext value using hybrid RSA+AES-GCM.
         *
         * Process:
         *   1. Generate random AES-256-GCM key + 12-byte IV
         *   2. Wrap plaintext in JSON envelope (value + timestamp + nonce)
         *   3. Encrypt envelope with AES-256-GCM → ciphertext + auth tag
         *   4. Encrypt AES key with RSA-OAEP (SHA-1) → wrapped key
         *   5. Output: "ENC2:" + base64( wrappedKeyLen(2) | wrappedKey | iv(12) | ciphertext+tag )
         *
         * Advantages over direct RSA:
         *   - No RSA payload size limit (AES handles any size)
         *   - AES-GCM provides authenticated encryption (integrity + confidentiality)
         *   - RSA-OAEP only encrypts 32 bytes (the AES key), well within safe limits
         *
         * Falls back to legacy "ENC:" format if AES-GCM is unavailable.
         *
         * @param {string} plaintext  Value to encrypt
         * @return {Promise<string>}  Resolves to "ENC2:" + base64 payload
         */
        encryptField: function (plaintext) {
            if (!initialized || !publicCryptoKey) {
                return Promise.resolve(plaintext);
            }

            var envelope = JSON.stringify({
                value: plaintext,
                ts: Date.now(),
                nonce: randomHex(16)
            });

            var envelopeBytes = stringToBytes(envelope);

            // Try hybrid AES-GCM + RSA-OAEP
            return crypto.subtle.generateKey(
                { name: 'AES-GCM', length: 256 },
                true,  // extractable (we need to export it for RSA wrapping)
                ['encrypt']
            ).then(function (aesKey) {
                var iv = crypto.getRandomValues(new Uint8Array(12));

                // Encrypt data with AES-GCM
                return crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv: iv, tagLength: 128 },
                    aesKey,
                    envelopeBytes
                ).then(function (aesCiphertext) {
                    // Export the raw AES key
                    return crypto.subtle.exportKey('raw', aesKey).then(function (rawKey) {
                        // Wrap the AES key with RSA-OAEP
                        return crypto.subtle.encrypt(
                            { name: 'RSA-OAEP' },
                            publicCryptoKey,
                            rawKey
                        ).then(function (wrappedKey) {
                            // Build the combined payload:
                            // 2 bytes (wrapped key length, big-endian) + wrappedKey + iv(12) + aesCiphertext
                            var wrappedArr = new Uint8Array(wrappedKey);
                            var aesArr = new Uint8Array(aesCiphertext);
                            var lenBytes = new Uint8Array(2);
                            lenBytes[0] = (wrappedArr.length >> 8) & 0xFF;
                            lenBytes[1] = wrappedArr.length & 0xFF;

                            var combined = new Uint8Array(2 + wrappedArr.length + 12 + aesArr.length);
                            combined.set(lenBytes, 0);
                            combined.set(wrappedArr, 2);
                            combined.set(iv, 2 + wrappedArr.length);
                            combined.set(aesArr, 2 + wrappedArr.length + 12);

                            return 'ENC2:' + arrayBufferToBase64(combined.buffer);
                        });
                    });
                });
            }).catch(function (err) {
                // Fallback: try legacy direct RSA encryption
                if (typeof console !== 'undefined') {
                    console.warn('FieldEncrypt: AES-GCM failed, falling back to direct RSA:', err);
                }
                return crypto.subtle.encrypt(
                    { name: 'RSA-OAEP' },
                    publicCryptoKey,
                    envelopeBytes
                ).then(function (encrypted) {
                    return 'ENC:' + arrayBufferToBase64(encrypted);
                }).catch(function (err2) {
                    if (typeof console !== 'undefined') {
                        console.error('FieldEncrypt: All encryption failed:', err2);
                    }
                    return plaintext;
                });
            });
        },

        /**
         * Encrypt specific named fields in a form before submission.
         *
         * @param {HTMLFormElement} formElement
         * @param {string[]} fieldNames  Array of field name attributes to encrypt
         * @return {Promise} Resolves when all fields are encrypted
         */
        encryptForm: function (formElement, fieldNames) {
            if (!initialized || !publicCryptoKey) {
                return Promise.resolve();
            }

            var promises = [];

            for (var i = 0; i < fieldNames.length; i++) {
                (function (name) {
                    var field = formElement.querySelector('[name="' + name + '"]');
                    if (field && field.value && field.value.length > 0) {
                        var p = window.FieldEncrypt.encryptField(field.value).then(function (encrypted) {
                            field.value = encrypted;
                        });
                        promises.push(p);
                    }
                })(fieldNames[i]);
            }

            return Promise.all(promises);
        },

        /**
         * Auto-discover and protect forms with data-encrypt="true".
         * Finds all fields with data-sensitive="true" and encrypts them
         * on form submit.
         */
        autoProtect: function () {
            var forms = document.querySelectorAll('form[data-encrypt="true"]');
            for (var f = 0; f < forms.length; f++) {
                (function (form) {
                    // Prevent double-binding
                    if (form.dataset.feProtected === 'true') return;
                    form.dataset.feProtected = 'true';

                    form.addEventListener('submit', function (e) {
                        if (!initialized || !publicCryptoKey) {
                            // No encryption available — let form submit normally
                            return;
                        }

                        // Find all sensitive fields
                        var sensitiveFields = form.querySelectorAll('[data-sensitive="true"]');
                        if (sensitiveFields.length === 0) return;

                        // Prevent default submit, encrypt, then re-submit
                        e.preventDefault();

                        var promises = [];
                        for (var i = 0; i < sensitiveFields.length; i++) {
                            (function (field) {
                                if (field.value && field.value.length > 0 && field.value.indexOf('ENC:') !== 0) {
                                    var p = window.FieldEncrypt.encryptField(field.value).then(function (encrypted) {
                                        field.value = encrypted;
                                    });
                                    promises.push(p);
                                }
                            })(sensitiveFields[i]);
                        }

                        Promise.all(promises).then(function () {
                            // Mark as already encrypted to avoid re-encrypt on re-submit
                            form.dataset.feProtected = 'submitted';
                            // Use HTMLFormElement.submit() to bypass the listener
                            HTMLFormElement.prototype.submit.call(form);
                        }).catch(function (err) {
                            if (typeof console !== 'undefined') {
                                console.error('FieldEncrypt: Auto-protect failed:', err);
                            }
                            // Submit unencrypted rather than block the user
                            HTMLFormElement.prototype.submit.call(form);
                        });
                    });
                })(forms[f]);
            }
        },

        /**
         * Check if encryption is initialized and ready.
         * @return {boolean}
         */
        isReady: function () {
            return initialized && publicCryptoKey !== null;
        },

        /**
         * Check if initialization failed (no Web Crypto support).
         * @return {boolean}
         */
        isFailed: function () {
            return initFailed;
        }
    };
})();
