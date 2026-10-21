document.addEventListener('DOMContentLoaded', async () => {
    const syncStatusEl = document.getElementById('sync-status');
    const syncRoleEl = document.getElementById('sync-role');

    // Retrieve CSRF token from document head
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    try {
        // Fetch authenticated user dashboard metadata via REST API
        const response = await axios.get('/api/v1/dashboard', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken || '',
                'Accept': 'application/json',
            },
            withCredentials: true,
        });

        if (response.data && response.data.data) {
            const data = response.data.data;
            if (syncStatusEl) {
                syncStatusEl.textContent = `REST API: Active (${data.user.email})`;
            }
            if (syncRoleEl) {
                syncRoleEl.textContent = `${data.role} Active Session`;
            }
        }
    } catch (error) {
        if (error.response && error.response.status === 401) {
            // Redirect to login if user session is unauthenticated
            window.location.href = '/login';
        } else if (syncStatusEl) {
            syncStatusEl.textContent = 'REST API: Offline or Unreachable';
        }
    }
});
