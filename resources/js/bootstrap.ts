import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Firefox cancels running requests as soon as the page reloads or navigates away, and axios then
 * rejects them with "Request aborted" (an Inertia reload, a last autosave). Nothing failed for the
 * person — the page is leaving — so such a rejection isn't reported as an uncaught error. Timeouts
 * ("timeout of … exceeded") and failed responses still surface as before.
 */
window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason;
    if (axios.isAxiosError(reason) && reason.code === 'ECONNABORTED' && reason.message === 'Request aborted') {
        event.preventDefault();
    }
});
