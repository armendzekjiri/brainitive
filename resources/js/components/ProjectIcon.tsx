import { Project } from '@/types';

type Size = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

const sizeClasses: Record<Size, string> = {
    xs: 'size-5 rounded',
    sm: 'size-6 rounded',
    md: 'size-9 rounded-lg',
    lg: 'size-12 rounded-xl',
    xl: 'size-16 rounded-2xl',
};

const emojiSizeClasses: Record<Size, string> = {
    xs: 'text-xs',
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-xl',
    xl: 'text-2xl',
};

interface ProjectIconProps {
    project: Pick<Project, 'icon_display' | 'icon' | 'key' | 'color'>;
    size?: Size;
    className?: string;
}

/** Renders project icon (image or emoji) when set, otherwise colored key fallback. Use everywhere a project is displayed. */
export function ProjectIcon({ project, size = 'sm', className = '' }: ProjectIconProps) {
    const display = project.icon_display ?? project.icon ?? null;
    const isUrl =
        typeof display === 'string' &&
        (display.startsWith('http') || display.startsWith('/'));

    const boxClass = sizeClasses[size];
    const emojiClass = emojiSizeClasses[size];

    return (
        <div
            className={`flex items-center justify-center shrink-0 overflow-hidden ${boxClass} ${className}`}
            style={
                !display
                    ? { backgroundColor: project.color || '#2563eb' }
                    : undefined
            }
        >
            {isUrl ? (
                <img
                    src={display}
                    alt=""
                    className="size-full object-contain"
                />
            ) : display ? (
                <span className={`${emojiClass} leading-none`}>
                    {display}
                </span>
            ) : (
                <span className="text-white font-bold text-[0.6em] leading-none">
                    {project.key?.substring(0, 2) || 'PR'}
                </span>
            )}
        </div>
    );
}
