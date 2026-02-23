import * as React from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ImagePlus } from 'lucide-react';

interface IconPickerProps {
    value: string;
    onChange: (value: string) => void;
    onImageUpload?: (file: File) => Promise<string>;
    label?: string;
    placeholderEmoji?: string;
    id?: string;
    className?: string;
    triggerClassName?: string;
    /** Emoji list to show in the grid. Defaults to a built-in set. */
    emojis?: string[];
}

const DEFAULT_EMOJIS = [
    '📑', '📚', '🔖', '📌', '📎', '📁', '📂', '📄', '📃', '📋',
    '⭐', '🌟', '💡', '🔥', '❤️', '✅', '🗂️', '📥', '📤', '🎯',
];

function isImageUrl(value: string): boolean {
    if (!value || value.length > 500) return false;
    return value.startsWith('http') || value.startsWith('/');
}

export function IconPicker({
    value,
    onChange,
    onImageUpload,
    label = 'Icon',
    placeholderEmoji = '📑',
    id,
    className,
    triggerClassName,
    emojis = DEFAULT_EMOJIS,
}: IconPickerProps) {
    const [open, setOpen] = React.useState(false);
    const [uploading, setUploading] = React.useState(false);
    const fileInputRef = React.useRef<HTMLInputElement>(null);

    const displayValue = value || placeholderEmoji;
    const showAsImage = isImageUrl(value);

    const handleEmojiSelect = (emoji: string) => {
        onChange(emoji);
        setOpen(false);
    };

    const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file || !onImageUpload) return;
        const isImage = file.type === 'image/png' || file.type === 'image/jpeg' || file.type === 'image/jpg'
            || /\.(png|jpe?g)$/i.test(file.name);
        if (!isImage) return;
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
                <Label htmlFor={id} className="block mb-2">
                    {label}
                </Label>
            )}
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        className={`h-10 w-10 p-0 shrink-0 overflow-hidden ${triggerClassName ?? ''}`}
                    >
                        {showAsImage ? (
                            <img
                                src={displayValue}
                                alt=""
                                className="h-full w-full object-contain"
                            />
                        ) : (
                            <span className="text-xl">{displayValue}</span>
                        )}
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-auto p-2" align="start">
                    <div className="grid grid-cols-5 gap-1">
                        {emojis.map((emoji) => (
                            <button
                                key={emoji}
                                type="button"
                                onClick={() => handleEmojiSelect(emoji)}
                                className="h-8 w-8 flex items-center justify-center rounded hover:bg-muted text-lg"
                            >
                                {emoji}
                            </button>
                        ))}
                    </div>
                    {onImageUpload && (
                        <>
                            <div className="my-2 border-t pt-2">
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/png,image/jpeg,image/jpg,.png,.jpg,.jpeg"
                                    className="hidden"
                                    onChange={handleFileChange}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="w-full gap-2"
                                    disabled={uploading}
                                    onClick={() => fileInputRef.current?.click()}
                                >
                                    <ImagePlus className="size-4" />
                                    {uploading ? 'Uploading…' : 'Upload PNG'}
                                </Button>
                            </div>
                        </>
                    )}
                </PopoverContent>
            </Popover>
        </div>
    );
}
