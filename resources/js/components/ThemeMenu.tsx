import { Check, Monitor, Moon, Sun } from 'lucide-react';
import {
    DropdownMenuLabel,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { Theme, useTheme } from '@/hooks/useTheme';

const OPTIONS: { value: Theme; label: string; Icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', Icon: Sun },
    { value: 'dark', label: 'Dark', Icon: Moon },
    { value: 'system', label: 'System', Icon: Monitor },
];

export function ThemeMenu() {
    const { theme, setTheme } = useTheme();

    return (
        <>
            <DropdownMenuLabel className="text-xs font-medium text-muted-foreground px-2 py-1.5 mt-1">
                Appearance
            </DropdownMenuLabel>
            {OPTIONS.map(({ value, label, Icon }) => (
                <DropdownMenuItem
                    key={value}
                    onSelect={(e) => {
                        e.preventDefault();
                        setTheme(value);
                    }}
                    className="flex items-center gap-3 p-2 cursor-pointer rounded-md"
                >
                    <Icon className="size-4" />
                    <span className="flex-1 text-sm">{label}</span>
                    {theme === value && <Check className="size-4 text-primary" />}
                </DropdownMenuItem>
            ))}
        </>
    );
}
