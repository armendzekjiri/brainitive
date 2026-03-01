import { useState, useRef, lazy, Suspense } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

const EmojiPickerLazy = lazy(() => import('@emoji-mart/react'));
const loadEmojiData = () => import('@emoji-mart/data').then((m) => m.default);

function EmojiMartPicker(props: React.ComponentProps<typeof EmojiPickerLazy>) {
    return (
        <Suspense fallback={<div className="p-2 text-sm text-muted-foreground">Loading…</div>}>
            <EmojiPickerLazy data={loadEmojiData} {...props} />
        </Suspense>
    );
}

export interface EmojiMartIconPickerProps {
    value: string;
    onChange: (val: string) => void;
    onImageUpload?: (file: File) => Promise<string>;
    label?: string;
    placeholderEmoji?: string;
    className?: string;
    /** 'sm' = 40px (matches input height), 'md' = 56px default */
    size?: 'sm' | 'md';
}

/**
 * Icon picker with full emoji-mart grid + custom image upload (same UX as bookmarks).
 */
export function EmojiMartIconPicker({
    value,
    onChange,
    onImageUpload,
    label,
    placeholderEmoji = '📑',
    className,
    size = 'md',
}: EmojiMartIconPickerProps) {
    const [open, setOpen] = useState(false);
    const [tab, setTab] = useState<'emoji' | 'upload'>('emoji');
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    const isImage = value?.startsWith('http') || value?.startsWith('/') || value?.startsWith('data:');
    const displayValue = value || placeholderEmoji;

    const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file || !onImageUpload) return;
        setUploading(true);
        try {
            const url = await onImageUpload(file);
            onChange(url);
            setOpen(false);
        } finally {
            setUploading(false);
        }
    };

    return (
        <div className={className}>
            {label && (
                <Label className="block mb-2">{label}</Label>
            )}
            <div className="flex flex-col items-start gap-2">
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className={cn(
                        'group relative flex items-center justify-center rounded-lg border border-border/60 bg-background transition-colors hover:bg-accent',
                        size === 'sm' ? 'size-10 text-2xl' : 'size-14 text-4xl',
                    )}
                    title="Change icon"
                >
                    {isImage ? (
                        <img src={value} className={size === 'sm' ? 'size-8 rounded-md object-cover' : 'size-10 rounded-lg object-cover'} alt="icon" />
                    ) : (
                        <span>{displayValue}</span>
                    )}
                    <span className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 group-hover:opacity-100 rounded-xl transition-opacity text-white text-xs font-medium">
                        Edit
                    </span>
                </button>
            </div>

            {open && (
                <div className="fixed inset-0 z-50 flex items-start justify-center pt-20" onClick={() => setOpen(false)}>
                    <div
                        className="bg-popover border rounded-xl shadow-2xl overflow-hidden w-[360px]"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex border-b">
                            <button
                                type="button"
                                className={cn(
                                    'flex-1 py-2 text-sm font-medium transition-colors',
                                    tab === 'emoji' ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                                onClick={() => setTab('emoji')}
                            >
                                😀 Emoji
                            </button>
                            <button
                                type="button"
                                className={cn(
                                    'flex-1 py-2 text-sm font-medium transition-colors',
                                    tab === 'upload' ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                                onClick={() => setTab('upload')}
                            >
                                🖼 Custom Image
                            </button>
                        </div>

                        {tab === 'emoji' && (
                            <EmojiMartPicker
                                onEmojiSelect={(emoji: { native: string }) => {
                                    onChange(emoji.native);
                                    setOpen(false);
                                }}
                                theme="light"
                                previewPosition="none"
                                skinTonePosition="search"
                                perLine={9}
                            />
                        )}

                        {tab === 'upload' && onImageUpload && (
                            <div className="p-6 flex flex-col items-center gap-4">
                                <p className="text-sm text-muted-foreground text-center">
                                    Upload a PNG or JPG image to use as the icon.
                                </p>
                                {isImage && (
                                    <img
                                        src={value}
                                        className="size-20 rounded-xl object-cover border"
                                        alt="current icon"
                                    />
                                )}
                                <input
                                    ref={fileRef}
                                    type="file"
                                    accept="image/png,image/jpeg,image/jpg,.png,.jpg,.jpeg"
                                    className="hidden"
                                    onChange={handleFileChange}
                                />
                                <button
                                    type="button"
                                    onClick={() => fileRef.current?.click()}
                                    className="px-4 py-2 rounded-lg border border-dashed border-primary text-sm font-medium hover:bg-accent transition-colors w-full text-center disabled:opacity-70"
                                    disabled={uploading}
                                >
                                    {uploading ? 'Uploading…' : 'Choose file…'}
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
