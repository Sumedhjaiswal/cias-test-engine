/* CIAS LMS Live Admin JS */
document.addEventListener('DOMContentLoaded', function () {
    const api = window.CIAS_LIVE;
    if (!api) return;

    document.querySelectorAll('.cias-lock-host').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.id;
            if (!confirm('Lock this Zoom host? It will not be assigned to new classes.')) return;
            await fetch(`${api.apiBase}/zoom-hosts/${id}/lock`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': api.nonce }
            });
            location.reload();
        });
    });

    document.querySelectorAll('.cias-unlock-host').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.id;
            await fetch(`${api.apiBase}/zoom-hosts/${id}/unlock`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': api.nonce }
            });
            location.reload();
        });
    });
});
