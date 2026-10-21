import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PLAN_COLORS } from './types';

export function ColorSwatches({ value, onChange, className }: { value?: string; onChange: (color: string) => void; className?: string }) {
    return (
        <div className={cn('grid grid-cols-5 gap-1.5', className)} role="radiogroup" aria-label="Bar colour">
            {PLAN_COLORS.map((color) => {
                const active = value?.toUpperCase() === color.value.toUpperCase();
                return (
                    <button
                        key={color.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        title={color.name}
                        onClick={() => onChange(color.value)}
                        className={cn('grid size-6 place-items-center rounded-md ring-offset-2 ring-offset-background transition hover:scale-110', active && 'ring-2 ring-foreground/60')}
                        style={{ background: color.value }}
                    >
                        {active && <Check className="size-3.5 text-white" />}
                    </button>
                );
            })}
        </div>
    );
}
