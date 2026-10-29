import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Browsers cancel running requests as soon as the page reloads or navigates away, and axios then
 * rejects them — Firefox with "Request aborted", Safari / WebKit with "Network Error". Nothing failed
 * for the person — the page is leaving — so such a rejection isn't reported as an uncaught error.
 * A network error while the page stays, timeouts ("timeout of … exceeded") and failed responses
 * still surface as before.
 */
let leavingPage = false;
window.addEventListener('beforeunload', () => {
    leavingPage = true;
});
window.addEventListener('pagehide', () => {
    leavingPage = true;
});
window.addEventListener('pageshow', () => {
    leavingPage = false;
});
window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason;
    if (!axios.isAxiosError(reason)) return;
    const aborted = reason.code === 'ECONNABORTED' && reason.message === 'Request aborted';
    if (aborted || (leavingPage && reason.code === 'ERR_NETWORK')) {
        event.preventDefault();
    }
});
