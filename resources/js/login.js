document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const rememberInput = document.getElementById('remember');
    const submitBtn = document.getElementById('login-btn');
    const btnText = document.getElementById('btn-text');
    const btnSpinner = document.getElementById('btn-spinner');
    const alertBox = document.getElementById('alert-box');
    const emailError = document.getElementById('email-error');
    const passwordError = document.getElementById('password-error');

    function clearErrors() {
        if (alertBox) {
            alertBox.style.display = 'none';
            alertBox.textContent = '';
        }
        if (emailError) {
            emailError.style.display = 'none';
            emailError.textContent = '';
        }
        if (passwordError) {
            passwordError.style.display = 'none';
            passwordError.textContent = '';
        }
    }

    function setLoading(isLoading) {
        if (!submitBtn) return;
        submitBtn.disabled = isLoading;
        if (isLoading) {
            if (btnText) btnText.textContent = 'Authenticating...';
            if (btnSpinner) btnSpinner.style.display = 'block';
        } else {
            if (btnText) btnText.textContent = 'Sign In';
            if (btnSpinner) btnSpinner.style.display = 'none';
        }
    }

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearErrors();

            const email = emailInput?.value.trim() ?? '';
            const password = passwordInput?.value ?? '';
            const remember = rememberInput?.checked ?? false;

            if (!email) {
                if (emailError) {
                    emailError.textContent = 'Please enter your email address.';
                    emailError.style.display = 'block';
                }
                emailInput?.focus();
                return;
            }

            if (!password) {
                if (passwordError) {
                    passwordError.textContent = 'Please enter your password.';
                    passwordError.style.display = 'block';
                }
                passwordInput?.focus();
                return;
            }

            setLoading(true);

            try {
                // Async login via REST API per Section 3.3 & Fig 3.1
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                
                const response = await axios.post('/api/v1/auth/login', {
                    email: email,
                    password: password,
                    remember: remember,
                }, {
                    headers: {
                        'X-CSRF-TOKEN': csrfToken || '',
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    withCredentials: true
                });

                if (response.data && response.data.data) {
                    const redirectUrl = response.data.data.redirect || '/dashboard';
                    if (alertBox) {
                        alertBox.className = 'alert-box alert-success';
                        alertBox.textContent = 'Authentication successful! Redirecting...';
                        alertBox.style.display = 'block';
                    }
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 400);
                }
            } catch (error) {
                setLoading(false);

                if (error.response) {
                    const status = error.response.status;
                    const data = error.response.data;

                    if (status === 422) {
                        // Section 20.2 standard format: { error: { code: 'VALIDATION_FAILED', details: [...] } }
                        if (data.error && data.error.details) {
                            let unassigned = [];
                            data.error.details.forEach(item => {
                                if (item.field === 'email' && emailError) {
                                    emailError.textContent = item.issue;
                                    emailError.style.display = 'block';
                                } else if (item.field === 'password' && passwordError) {
                                    passwordError.textContent = item.issue;
                                    passwordError.style.display = 'block';
                                } else {
                                    unassigned.push(item.issue);
                                }
                            });
                            if (unassigned.length > 0 && alertBox) {
                                alertBox.className = 'alert-box alert-danger';
                                alertBox.textContent = unassigned.join(' ');
                                alertBox.style.display = 'block';
                            }
                        } else if (data.errors) {
                            if (data.errors.email && emailError) {
                                emailError.textContent = data.errors.email[0];
                                emailError.style.display = 'block';
                            }
                            if (data.errors.password && passwordError) {
                                passwordError.textContent = data.errors.password[0];
                                passwordError.style.display = 'block';
                            }
                        } else if (alertBox) {
                            alertBox.className = 'alert-box alert-danger';
                            alertBox.textContent = data.message || 'Invalid credentials.';
                            alertBox.style.display = 'block';
                        }
                    } else if (status === 429) {
                        let retryAfter = 900;
                        if (data.error && data.error.retry_after) {
                            retryAfter = parseInt(data.error.retry_after, 10);
                        } else if (error.response.headers && error.response.headers['retry-after']) {
                            retryAfter = parseInt(error.response.headers['retry-after'], 10);
                        }

                        const mins = Math.max(1, Math.ceil(retryAfter / 60));
                        const msg = data.error?.message || data.message || `Too many failed login attempts. For security reasons, your account is temporarily locked. Please try again in ${mins} minutes.`;

                        if (alertBox) {
                            alertBox.className = 'alert-box alert-danger';
                            alertBox.textContent = msg;
                            alertBox.style.display = 'block';
                        }

                        startLockoutCountdown(retryAfter);
                    } else if (alertBox) {
                        alertBox.className = 'alert-box alert-danger';
                        alertBox.textContent = data.error?.message || data.message || 'Authentication failed. Please check credentials.';
                        alertBox.style.display = 'block';
                    }
                } else {
                    // Fallback to standard form submission if network error
                    form.submit();
                }
            }
        });
    }

    let countdownInterval = null;

    function startLockoutCountdown(totalSeconds) {
        if (!submitBtn) return;
        submitBtn.disabled = true;
        let remaining = totalSeconds;

        if (countdownInterval) {
            clearInterval(countdownInterval);
        }

        const updateBtn = () => {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            const formatted = `${mins}:${secs < 10 ? '0' : ''}${secs}`;
            if (btnText) btnText.textContent = `Locked (${formatted})`;
            if (btnSpinner) btnSpinner.style.display = 'none';
        };

        updateBtn();

        countdownInterval = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                submitBtn.disabled = false;
                if (btnText) btnText.textContent = 'Sign In';
                if (alertBox) {
                    alertBox.className = 'alert-box alert-success';
                    alertBox.textContent = 'Lockout expired. You may now attempt to sign in again.';
                }
            } else {
                updateBtn();
            }
        }, 1000);
    }
});
