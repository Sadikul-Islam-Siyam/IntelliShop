/**
 * IntelliShop Auth Module
 * Handles login interactions, inline validation, and session redirection.
 */

import api from '../api';

export function initLoginForm(formSelector = '#login-form') {
    const form = document.querySelector(formSelector);
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = form.querySelector('#email')?.value.trim();
        const password = form.querySelector('#password')?.value;
        const remember = form.querySelector('#remember')?.checked ?? false;

        try {
            const { data } = await api.post('/auth/login', {
                email,
                password,
                remember,
            });

            if (data?.data?.redirect) {
                window.location.href = data.data.redirect;
            }
        } catch (error) {
            console.error('Login error:', error);
        }
    });
}
