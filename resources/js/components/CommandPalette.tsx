/**
 * CommandPalette — opens with Ctrl+.
 *
 * Provides system-level commands across all pages.
 * Uses the same cmdk/Dialog pattern as SearchModal.
 */

import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Command as CommandPrimitive } from 'cmdk';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { LayoutDashboard, FolderKanban, Search, X } from 'lucide-react';

// ── Command definition ────────────────────────────────────────────────────────
export interface PaletteCommand {
    id: string;
    label: string;
    description?: string;
    icon: React.ReactNode;
    group: string;
    action: () => void;
}

interface CommandPaletteProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Extra commands injected from AppLayout (e.g. createStickyNote) */
    extraCommands?: PaletteCommand[];
}

// ── Icon wrapper ──────────────────────────────────────────────────────────────
export function CmdIcon({ children, color }: { children: React.ReactNode; color?: string }) {
    return (
        <div
            className="flex size-8 shrink-0 items-center justify-center rounded-lg"
            style={{
                background: color ?? 'hsl(var(--muted))',
                color: color ? 'white' : 'hsl(var(--muted-foreground))',
            }}
        >
            {children}
        </div>
    );
}

// ── Main component ────────────────────────────────────────────────────────────
export function CommandPalette({ open, onOpenChange, extraCommands = [] }: CommandPaletteProps) {
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    // Focus input when opened
    useEffect(() => {
        if (open) {
            setQuery('');
            setTimeout(() => inputRef.current?.focus(), 50);
        }
    }, [open]);

    // Built-in navigation commands
    const builtInCommands: PaletteCommand[] = [
        {
            id: 'nav-dashboard',
            label: 'Go to Dashboard',
            icon: <CmdIcon><LayoutDashboard className="size-4" /></CmdIcon>,
            group: 'Navigation',
            action: () => router.visit('/dashboard'),
        },
        {
            id: 'nav-my-work',
            label: 'Go to My Work',
            icon: <CmdIcon><FolderKanban className="size-4" /></CmdIcon>,
            group: 'Navigation',
            action: () => router.visit('/my-work'),
        },
    ];

    const allCommands: PaletteCommand[] = [...builtInCommands, ...extraCommands];

    // Group commands preserving insertion order
    const groups = Array.from(new Set(allCommands.map((c) => c.group)));

    const filtered = query.trim()
        ? allCommands.filter(
              (c) =>
                  c.label.toLowerCase().includes(query.toLowerCase()) ||
                  c.description?.toLowerCase().includes(query.toLowerCase()),
          )
        : allCommands;

    const run = (cmd: PaletteCommand) => {
        onOpenChange(false);
        // slight delay so dialog closes before action navigates
        setTimeout(() => cmd.action(), 80);
    };

    const CommandRow = ({ cmd }: { cmd: PaletteCommand }) => (
        <CommandItem
            key={cmd.id}
            value={cmd.id}
            onSelect={() => run(cmd)}
            className="flex items-center gap-3 cursor-pointer rounded-lg px-2 py-2.5 aria-selected:bg-accent"
        >
            {cmd.icon}
            <div className="flex flex-col min-w-0">
                <span className="text-sm font-medium leading-snug">{cmd.label}</span>
                {cmd.description && (
                    <span className="text-xs text-muted-foreground truncate">{cmd.description}</span>
                )}
            </div>
        </CommandItem>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="overflow-hidden p-0 shadow-2xl [&>button]:hidden focus:outline-none focus-visible:outline-none focus-visible:ring-0"
                style={{ maxWidth: 660 }}
            >
                <Command
                    shouldFilter={false}
                    className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-semibold [&_[cmdk-group-heading]]:uppercase [&_[cmdk-group-heading]]:tracking-wide [&_[cmdk-group-heading]]:text-muted-foreground/70 [&_[cmdk-group]:not([hidden])_~[cmdk-group]]:pt-0 [&_[cmdk-group]]:px-2"
                >
                    {/* Header: search icon · input · ESC badge · X button */}
                    <div className="flex items-center gap-2 border-b px-4 py-3">
                        <Search className="size-4 shrink-0 text-muted-foreground/60" />
                        <CommandPrimitive.Input
                            ref={inputRef}
                            placeholder="Type a command or search..."
                            value={query}
                            onValueChange={setQuery}
                            className="flex-1 bg-transparent text-sm outline-none ring-0 border-0 shadow-none placeholder:text-muted-foreground/50 focus:outline-none focus:ring-0"
                        />
                        <kbd className="hidden sm:inline-flex h-5 select-none items-center rounded border border-border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground">
                            ESC
                        </kbd>
                        <button
                            onClick={() => onOpenChange(false)}
                            className="ml-1 flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground/60 hover:bg-muted hover:text-foreground transition-colors"
                            aria-label="Close"
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>

                    <CommandList className="max-h-[480px] overflow-y-auto overflow-x-hidden py-2">
                        <CommandEmpty className="py-10 text-center text-sm text-muted-foreground">
                            No commands found for &ldquo;{query}&rdquo;.
                        </CommandEmpty>

                        {query.trim()
                            ? // flat filtered list — no group headings when searching
                              filtered.length > 0 && (
                                  <CommandGroup>
                                      {filtered.map((cmd) => (
                                          <CommandRow key={cmd.id} cmd={cmd} />
                                      ))}
                                  </CommandGroup>
                              )
                            : // grouped view
                              groups.map((group) => (
                                  <CommandGroup key={group} heading={group}>
                                      {allCommands
                                          .filter((c) => c.group === group)
                                          .map((cmd) => (
                                              <CommandRow key={cmd.id} cmd={cmd} />
                                          ))}
                                  </CommandGroup>
                              ))}
                    </CommandList>

                    <div className="border-t px-4 py-2.5 flex items-center gap-5 text-[11px] text-muted-foreground/70 select-none">
                        <span><kbd className="font-mono font-medium text-muted-foreground">↑↓</kbd> navigate</span>
                        <span><kbd className="font-mono font-medium text-muted-foreground">↵</kbd> run</span>
                        <span><kbd className="font-mono font-medium text-muted-foreground">ESC</kbd> close</span>
                        <span className="ml-auto"><kbd className="font-mono font-medium text-muted-foreground">Ctrl+.</kbd> toggle</span>
                    </div>
                </Command>
            </DialogContent>
        </Dialog>
    );
}
