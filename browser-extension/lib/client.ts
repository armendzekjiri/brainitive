import { createApi } from './api';
import { send } from './messages';

/** API client for extension pages: tokens come from the background (single refresh). */
export const api = createApi((force) => send<{ token: string; server: string }>({ type: 'get-token', force }));
