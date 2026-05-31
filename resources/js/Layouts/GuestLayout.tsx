import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTheme } from '@/hooks/useTheme';

export default function Guest({ children }: PropsWithChildren) {
    const { resolvedTheme, toggleTheme } = useTheme();

    return (
        <div className="flex min-h-screen flex-col items-center bg-background pt-6 sm:justify-center sm:pt-0">
            <div className="absolute top-4 right-4">
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={toggleTheme}
                    title={resolvedTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                >
                    {resolvedTheme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                </Button>
            </div>

            <div>
                <Link href="/" className="block">
                    <ApplicationLogo className="h-20 w-20 object-contain" />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-card px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg border border-border">
                {children}
            </div>
        </div>
    );
}
