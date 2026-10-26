import { useState } from 'react';
import { X } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { isEmail, splitEmails } from '@/lib/meetings';

/**
 * Email addresses as removable chips (BRAI-147): type or paste, Enter / comma / space adds.
 * Invalid entries stay in the field with a hint; `max` caps the total (with `taken` others).
 */
export default function EmailChipsInput({
    id, value, onChange, max, taken = 0, placeholder = 'name@example.com',
}: {
    id?: string;
    value: string[];
    onChange: (emails: string[]) => void;
    max: number;
    /** attendees already picked elsewhere (members), counted against `max` */
    taken?: number;
    placeholder?: string;
}) {
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | null>(null);

    const commit = (text: string): boolean => {
        const entries = splitEmails(text);
        if (entries.length === 0) return true;
        const bad = entries.filter((e) => !isEmail(e));
        const known = new Set(value.map((v) => v.toLowerCase()));
        const fresh = entries.filter((e) => isEmail(e) && !known.has(e.toLowerCase()));
        const room = Math.max(0, max - taken - value.length);
        if (fresh.length > room) {
            setError(`You can invite at most ${max} people.`);
            onChange([...value, ...fresh.slice(0, room)]);
            setDraft('');
            return false;
        }
        onChange([...value, ...fresh]);
        if (bad.length > 0) {
            setDraft(bad.join(', '));
            setError(`“${bad[0]}” isn’t an email address.`);
            return false;
        }
        setDraft('');
        setError(null);
        return true;
    };

    return (
        <div className="space-y-1.5">
            <div className="flex min-h-10 flex-wrap items-center gap-1.5 rounded-md border border-input bg-background px-2 py-1.5 focus-within:ring-2 focus-within:ring-ring">
                {value.map((email) => (
                    <span key={email} className="inline-flex max-w-full items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs">
                        <span className="truncate">{email}</span>
                        <button
                            type="button"
                            className="rounded-full text-muted-foreground hover:text-foreground"
                            aria-label={`Remove ${email}`}
                            onClick={() => onChange(value.filter((v) => v !== email))}
                        >
                            <X className="size-3" />
                        </button>
                    </span>
                ))}
                <Input
                    id={id}
                    value={draft}
                    onChange={(e) => { setDraft(e.target.value); setError(null); }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ',' || e.key === ';' || (e.key === ' ' && draft.trim())) {
                            if (draft.trim() || e.key === 'Enter') {
                                e.preventDefault();
                                commit(draft);
                            }
                        } else if (e.key === 'Backspace' && draft === '' && value.length > 0) {
                            onChange(value.slice(0, -1));
                        }
                    }}
                    onBlur={() => commit(draft)}
                    onPaste={(e) => {
                        const text = e.clipboardData.getData('text');
                        if (/[\s,;]/.test(text.trim())) {
                            e.preventDefault();
                            commit(`${draft} ${text}`);
                        }
                    }}
                    placeholder={value.length === 0 ? placeholder : ''}
                    className="h-7 min-w-[10rem] flex-1 border-0 px-1 shadow-none focus-visible:ring-0"
                    type="text"
                    inputMode="email"
                    autoComplete="off"
                />
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}
