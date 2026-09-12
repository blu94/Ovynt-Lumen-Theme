(function() {
    window.ThemeApi = window.ThemeApi || {};

    // Reading storage can THROW, not merely return null: a browser set to block site data
    // raises SecurityError on the getter itself. Unwrapped, that took down every ThemeApi call
    // before the request was even built. A signed-in customer in that browser has no token to
    // find anyway, so falling back to an anonymous request is the honest degrade. (From Saffron.)
    const storedToken = () => {
        try {
            return localStorage.getItem('customer_access_token');
        } catch (e) {
            return null;
        }
    };

    const getHeaders = () => {
        const token = storedToken();
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        if (token) headers['Authorization'] = `Bearer ${token}`;
        return headers;
    };

    // Generic JSON request helper. The cart/checkout page calls this for the checkout POST
    // and the payment-status GET; it returns parsed JSON and throws { data } on non-2xx,
    // matching the { data } shape the structured helpers below use.
    // Remove the browser's two copies of the customer token. Never called alone to
    // "log out" - revocation is signOut()'s job (audit S1); this also runs when the
    // server answers 401, so a lapsed token cannot keep the UI looking signed in
    // (audit S3).
    window.ThemeApi.clearSession = () => {
        window.OvyntAuthHint = false;
        // Legacy copies from before the cookie went HttpOnly (audit S2); the HttpOnly
        // cookie itself can only be cleared by the logout response.
        localStorage.removeItem('customer_access_token');
        document.cookie = 'customer_access_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
    };

    window.ThemeApi.request = async (url, method = 'GET', body = null) => {
        const options = { method, headers: getHeaders(), credentials: 'same-origin' };
        if (body !== null && String(method).toUpperCase() !== 'GET') {
            options.body = JSON.stringify(body);
        }
        const res = await fetch(url, options);
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            if (res.status === 401 && (window.OvyntAuthHint || storedToken())) {
                window.ThemeApi.clearSession();
            }
            throw { data: data || { message: 'Request failed' } };
        }
        return data;
    };

    // Encrypt a password in the browser before it leaves the page. A second
    // layer under HTTPS, not a replacement: the raw password never appears in a
    // request body, so it cannot be captured by a reverse-proxy access log, an
    // APM trace or a crash report.
    //
    // The public half of the store's RSA keypair is fetched once from
    // /api/auth/public-key. It is per-install and safe to serve to anyone; the
    // private half never leaves the server. RSA-OAEP with the hash the server
    // names, because PHP's openssl_private_decrypt() dictates it.
    //
    // Returns the input unchanged when encryption is impossible - no key, or
    // crypto.subtle absent because the page is not on HTTPS. The API accepts
    // plain text for exactly this reason; failing closed here would take the
    // login form away from a store that is still being set up.
    let keyPromise = null;

    const loadPublicKey = async () => {
        if (typeof crypto === 'undefined' || !crypto.subtle) return null;
        try {
            const res = await fetch('/api/auth/public-key', { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return null;
            const body = await res.json();
            if (!body.key) return null;

            const binary = atob(body.key);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);

            return await crypto.subtle.importKey(
                'spki', bytes,
                { name: 'RSA-OAEP', hash: body.hash || 'SHA-1' },
                false, ['encrypt']
            );
        } catch (e) {
            return null;
        }
    };

    const encryptPassword = async (text) => {
        if (!text) return text;
        if (!keyPromise) keyPromise = loadPublicKey();

        const key = await keyPromise;
        if (!key) return text;

        try {
            const buffer = await crypto.subtle.encrypt(
                { name: 'RSA-OAEP' }, key, new TextEncoder().encode(text)
            );
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);

            return btoa(binary);
        } catch (e) {
            return text;
        }
    };

    window.ThemeApi.encryptPassword = encryptPassword;

    window.ThemeApi.auth = {
        login: async (email, password) => {
            const res = await fetch('/api/auth/login/storefront', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ email, password: await encryptPassword(password) })
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Login failed' } };
            }
            return res.json();
        },
        register: async (name, email, password) => {
            const username = email.split('@')[0].replace(/[^a-zA-Z0-9]/g, '') + Math.floor(Math.random() * 10000);
            // Encrypted once and reused: the API compares the two fields after
            // decrypting, and RSA-OAEP is randomised, so encrypting twice would
            // produce different ciphertext for the same password. That is fine
            // for the server but wasteful, and reusing it keeps them identical.
            const encrypted = await encryptPassword(password);
            const res = await fetch('/api/auth/register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ name, username, email, password: encrypted, password_confirmation: encrypted })
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Registration failed' } };
            }
            return res.json();
        },
        // Always resolves with the API's deliberately generic message - the endpoint
        // does not reveal whether the address is registered, and neither should we.
        forgotPassword: async (email) =>
            window.ThemeApi.request('/api/auth/forgot-password', 'POST', { email }),

        // token and email come from the reset link's query string, not user input.
        resetPassword: async (token, email, password, password_confirmation) =>
            window.ThemeApi.request('/api/auth/reset-password', 'POST', {
                token,
                email,
                password: await encryptPassword(password),
                password_confirmation: await encryptPassword(password_confirmation)
            }),

        me: async () => {
            const res = await fetch('/api/user', {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to fetch profile' } };
            }
            return res.json();
        },
        // Server-side revocation (audit S1). Signing out must delete the Sanctum token,
        // not just the browser's copies - a copy in a proxy log or on a shared machine
        // stays valid for the rest of its 120-minute life otherwise. Callers clear
        // localStorage and the cookie in a finally around this, so a network failure
        // still signs the browser out locally.
        logout: async () => {
            const res = await fetch('/api/auth/logout', {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Logout failed' } };
            }
            return res.json();
        },
        // The one sign-out path every page uses: revoke on the server, then clear the
        // browser's copies regardless - an unreachable server must not leave the UI
        // signed in. Pass redirect: null to stay on the page.
        signOut: async (redirect = '/login') => {
            try {
                await window.ThemeApi.auth.logout();
            } catch (e) {
                // Token already expired or revoked - clearing locally is still right.
            } finally {
                window.ThemeApi.clearSession();
                if (redirect) window.location.href = redirect;
            }
        },
        orders: async () => {
            const res = await fetch('/api/user/orders', {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to fetch orders' } };
            }
            return res.json();
        },
        order: async (orderNumber) => {
            const res = await fetch('/api/user/orders/' + encodeURIComponent(orderNumber), {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to fetch order' } };
            }
            return res.json();
        },
        updateProfile: async (name, email, password, password_confirmation, current_password, avatarFile) => {
            const formData = new FormData();
            formData.append('name', name);
            formData.append('email', email);
            if (password) {
                formData.append('password', await encryptPassword(password));
                formData.append('password_confirmation', await encryptPassword(password_confirmation));
                if (current_password) formData.append('current_password', await encryptPassword(current_password));
            }
            if (avatarFile) {
                formData.append('avatar', avatarFile);
            }
            formData.append('_method', 'PUT');

            const headers = getHeaders();
            delete headers['Content-Type'];

            const res = await fetch('/api/user', {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                body: formData
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to update profile' } };
            }
            return res.json();
        }
    };

    window.ThemeApi.addresses = {
        getAll: async () => {
            const res = await fetch('/api/user/addresses', {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Failed to fetch addresses');
            return res.json();
        },
        create: async (dataObj) => {
            const res = await fetch('/api/user/addresses', {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify(dataObj)
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to create address' } };
            }
            return res.json();
        },
        update: async (id, dataObj) => {
            const res = await fetch(`/api/user/addresses/${id}`, {
                method: 'PUT',
                headers: getHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify(dataObj)
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to update address' } };
            }
            return res.json();
        },
        delete: async (id) => {
            const res = await fetch(`/api/user/addresses/${id}`, {
                method: 'DELETE',
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Failed to delete address');
            return res.json();
        }
    };

    window.ThemeApi.interactions = {
        getComments: async (type, id, page = 1) => {
            const res = await fetch(`/api/storefront/interactions/${type}/${id}?page=${page}`, {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Network error' } };
            }
            return res.json();
        },
        
        postComment: async (type, id, body, email, name, parentId) => {
            const payload = { body };
            if (email) payload.email = email;
            if (name) payload.name = name;
            if (parentId) payload.parent_id = parentId;
            
            const res = await fetch(`/api/storefront/interactions/${type}/${id}/comment`, {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to post comment' } };
            }
            return res.json();
        },

        getCommentReactions: async (commentId) => {
            const res = await fetch(`/api/storefront/interactions/comment/${commentId}/reactions`, {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Failed to get reactions');
            return res.json();
        },

        toggleCommentReaction: async (commentId) => {
            const res = await fetch(`/api/storefront/interactions/comment/${commentId}/react`, {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Failed to toggle reaction');
            return res.json();
        },

        getReactions: async (type, id) => {
            const res = await fetch(`/api/storefront/interactions/${type}/${id}/reactions`, {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Failed to get reactions');
            return res.json();
        },

        toggleReaction: async (type, id) => {
            const res = await fetch(`/api/storefront/interactions/${type}/${id}/react`, {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Failed to toggle reaction');
            return res.json();
        }
    };
    window.ThemeApi.newsletter = {
        subscribe: async (endpoint, dataObj) => {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(dataObj)
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Subscription failed' } };
            }
            return res.json();
        }
    };

    window.ThemeApi.search = {
        query: async (q) => {
            const res = await fetch(`/api/storefront/search?q=${encodeURIComponent(q)}`, {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Search failed');
            return res.json();
        }
    };

    window.ThemeApi.cart = {
        validate: async (cartList) => {
            const res = await fetch('/api/storefront/cart/validate', {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ cart: cartList })
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Cart validation failed' } };
            }
            return res.json();
        },
        validateDiscount: async (code, cartList) => {
            const res = await fetch('/api/storefront/cart/discount/validate', {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ code: code, cart: cartList })
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Discount validation failed' } };
            }
            return res.json();
        }
    };

    window.ThemeApi.shipping = {
        options: async () => {
            const res = await fetch('/api/storefront/shipping/options', {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to load shipping options' } };
            }
            return res.json();
        },
        states: async (country) => {
            const res = await fetch(`/api/storefront/shipping/states?country=${encodeURIComponent(country)}`, {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to load states' } };
            }
            return res.json();
        },
        methods: async (cartList, country, state, discountCode) => {
            const res = await fetch('/api/storefront/shipping/methods', {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({
                    cart: cartList,
                    shipping_country: country,
                    shipping_state: state || null,
                    discountCode: discountCode || null
                })
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to load shipping methods' } };
            }
            return res.json();
        }
    };

    window.ThemeApi.tax = {
        // Estimated tax for the cart + destination, priced through the same TaxService as
        // checkout so the amount shown matches what is charged. shippingTotal lets shipping
        // tax (when the store enables it) be reflected in the estimate.
        preview: async (cartList, country, state, discountCode, shippingTotal) => {
            const res = await fetch('/api/storefront/tax/preview', {
                method: 'POST',
                headers: getHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({
                    cart: cartList,
                    shipping_country: country || null,
                    shipping_state: state || null,
                    discountCode: discountCode || null,
                    shipping_total: shippingTotal || 0
                })
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Failed to load tax' } };
            }
            return res.json();
        }
    };

    window.ThemeApi.forms = {
        get: async (slug) => {
            const res = await fetch(`/api/storefront/forms/${slug}`, {
                headers: getHeaders(),
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('Failed to fetch form');
            return res.json();
        },
        submit: async (slug, dataObj) => {
            const res = await fetch(`/api/storefront/forms/${slug}/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(dataObj)
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw { data: data || { message: 'Form submission failed' } };
            }
            return res.json();
        }
    };
})();
