import { formatDistanceToNow } from 'date-fns';
import type { ApiKeyArea, ApiKeyRow } from '@/types';

/** "3 days ago" / "in 89 days". */
export function ago(iso: string): string {
    return formatDistanceToNow(new Date(iso), { addSuffix: true });
}

/** Expiry choice label: 30 → "30 days", 365 → "1 year", null → "Never". */
export function expiryLabel(days: number | null): string {
    if (days === null) return 'Never';
    if (days === 365) return '1 year';
    return `${days} days`;
}

/** "All workspaces" / "Projects: A, B" (+ "+2 unavailable"). */
export function whereSummary(key: Pick<ApiKeyRow, 'scope_type' | 'scope' | 'scope_unavailable'>): string {
    if (key.scope_type === 'all') return 'All workspaces';
    const noun = { workspaces: 'Workspaces', projects: 'Projects', boards: 'Boards' }[key.scope_type];
    const names = key.scope.map((s) => s.name).join(', ');
    const parts = [names ? `${noun}: ${names}` : `${noun}: none available`];
    if (key.scope_unavailable > 0) parts.push(`+${key.scope_unavailable} unavailable`);
    return parts.join(' ');
}

/** "Issues & boards: read & write · Docs: read · Delete", or "No access". */
export function whatSummary(key: Pick<ApiKeyRow, 'abilities' | 'can_delete'>, areas: ApiKeyArea[]): string {
    const parts = areas
        .filter((area) => (key.abilities[area.key] ?? 'none') !== 'none')
        .map((area) => `${area.label}: ${key.abilities[area.key] === 'write' ? 'read & write' : 'read'}`);
    if (parts.length === 0) return 'No access';
    if (key.can_delete) parts.push('Delete');
    return parts.join(' · ');
}

/** "Expires in 89 days" / "Never expires" / "Expired 2 days ago" / "Revoked 1 hour ago". */
export function expirySummary(key: Pick<ApiKeyRow, 'status' | 'expires_at' | 'revoked_at'>): string {
    if (key.status === 'revoked') return key.revoked_at ? `Revoked ${ago(key.revoked_at)}` : 'Revoked';
    if (key.status === 'expired') return key.expires_at ? `Expired ${ago(key.expires_at)}` : 'Expired';
    return key.expires_at ? `Expires ${ago(key.expires_at)}` : 'Never expires';
}
