import { cn } from '@/lib/utils';

/** An uploaded page icon is a data: URL (or a URL); everything else is an emoji. */
export function isImageIcon(icon: string | null | undefined): icon is string {
    return !!icon && (icon.startsWith('data:') || icon.startsWith('http') || icon.startsWith('/'));
}

/**
 * A page's icon: the uploaded image, or the emoji. Never print an uploaded icon as text — it is a
 * long base64 string that overflows the layout (v1.41.2).
 */
export default function PageIcon({ icon, className, imageClassName }: { icon: string; className?: string; imageClassName?: string }) {
    if (isImageIcon(icon)) {
        return <img src={icon} alt="" className={cn('shrink-0 rounded object-cover', imageClassName)} />;
    }

    return <span className={cn('shrink-0 leading-none', className)}>{icon}</span>;
}
