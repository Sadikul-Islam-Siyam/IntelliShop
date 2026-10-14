/**
 * IntelliShop API Client (Axios Instance)
 * Configured per Section 3.3 & Section 20.2
 * BaseURL: /api/v1
 * Interceptors for 401 (Unauthenticated), 419 (CSRF/Session Expired), 422 (Validation)
 */

import axios from 'axios';

const api = axios.create({
    baseURL: '/api/v1',
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        'Content-Type': 'application/json',
    },
    withCredentials: true,
});

// Response interceptor
api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response ? error.response.status : null;

        if (status === 401) {
            console.warn('[API 401] Unauthenticated session.');
        } else if (status === 419) {
            console.warn('[API 419] CSRF token expired. Reloading page...');
            window.location.reload();
        } else if (status === 422) {
            console.warn('[API 422] Validation failed:', error.response.data);
        } else if (status === 429) {
            console.warn('[API 429] Throttled. Too many attempts.');
        }

        return Promise.reject(error);
    }
);

export default api;
