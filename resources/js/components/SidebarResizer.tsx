import { useCallback, useEffect, useState } from 'react';
import { useSidebar } from '@/components/ui/sidebar';

const STORAGE_KEY = 'sidebar-width-px';
const DEFAULT_WIDTH_PX = 256; // 16rem
const MIN_WIDTH_PX = 192; // 12rem
const MAX_WIDTH_PERCENT = 35;

function getMaxWidthPx(): number {
    if (typeof window === 'undefined') return 800;
    return Math.floor((window.innerWidth * MAX_WIDTH_PERCENT) / 100);
}

function getStoredWidth(): number {
    if (typeof window === 'undefined') return DEFAULT_WIDTH_PX;
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored == null) return DEFAULT_WIDTH_PX;
    const n = parseInt(stored, 10);
    if (Number.isNaN(n)) return DEFAULT_WIDTH_PX;
    return Math.max(MIN_WIDTH_PX, Math.min(getMaxWidthPx(), n));
}

interface SidebarResizerProps {
    width: number;
    onWidthChange: (width: number) => void;
}

export function SidebarResizer({ width, onWidthChange }: SidebarResizerProps) {
    const { state } = useSidebar();
    const [isDragging, setIsDragging] = useState(false);
    const isExpanded = state === 'expanded';

    const clamp = useCallback((value: number) => {
        const max = getMaxWidthPx();
        return Math.max(MIN_WIDTH_PX, Math.min(max, value));
    }, []);

    const handlePointerDown = useCallback(
        (e: React.PointerEvent) => {
            e.preventDefault();
            setIsDragging(true);
            (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
        },
        []
    );

    useEffect(() => {
        if (!isDragging) return;

        const handlePointerMove = (e: PointerEvent) => {
            const newWidth = clamp(e.clientX);
            onWidthChange(newWidth);
        };

        const handlePointerUp = () => {
            setIsDragging(false);
            try {
                localStorage.setItem(STORAGE_KEY, String(width));
            } catch {
                // ignore
            }
        };

        window.addEventListener('pointermove', handlePointerMove);
        window.addEventListener('pointerup', handlePointerUp);
        window.addEventListener('pointercancel', handlePointerUp);
        return () => {
            window.removeEventListener('pointermove', handlePointerMove);
            window.removeEventListener('pointerup', handlePointerUp);
            window.removeEventListener('pointercancel', handlePointerUp);
        };
    }, [isDragging, width, onWidthChange, clamp]);

    return (
        <div
            role="separator"
            aria-orientation="vertical"
            aria-label="Resize sidebar"
            onPointerDown={handlePointerDown}
            className={`hidden shrink-0 select-none touch-none cursor-col-resize hover:bg-border/80 active:bg-primary/20 transition-colors relative z-20 flex items-stretch ${isExpanded ? 'md:flex' : 'md:hidden'}`}
            style={{ width: 10, marginLeft: isExpanded ? -5 : 0, minWidth: 10 }}
            title="Drag to resize sidebar"
        >
            <span className="absolute inset-y-0 left-1/2 w-px -translate-x-px bg-border hover:bg-primary/30 transition-colors pointer-events-none" aria-hidden />
        </div>
    );
}

export function getInitialSidebarWidth(): number {
    return getStoredWidth();
}
