/**
 * IntelliShop — Audit Log Interactive Module
 *
 * Handles detail modal viewing and async client-side actions.
 */
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('details-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalContent = document.getElementById('modal-content');
    const btnCloseModal = document.getElementById('btn-close-modal');

    // Attach click listeners to all 'View Payload' buttons
    document.querySelectorAll('.btn-details').forEach(button => {
        button.addEventListener('click', () => {
            const id = button.getAttribute('data-id');
            const action = button.getAttribute('data-action');
            const rawDetails = button.getAttribute('data-details');

            modalTitle.textContent = `Audit #${id} — ${action}`;

            try {
                // Attempt to format as indented JSON if possible
                const parsed = JSON.parse(rawDetails);
                modalContent.textContent = JSON.stringify(parsed, null, 2);
            } catch (e) {
                modalContent.textContent = rawDetails || 'No additional details provided.';
            }

            modal.classList.add('active');
        });
    });

    // Close modal handlers
    if (btnCloseModal) {
        btnCloseModal.addEventListener('click', () => {
            modal.classList.remove('active');
        });
    }

    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    }

    // Keyboard ESC to close modal
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) {
            modal.classList.remove('active');
        }
    });
});
