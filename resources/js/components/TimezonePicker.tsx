import { useMemo, useState } from 'react';
import { Check, ChevronsUpDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/** "GMT+5:30" for a zone right now, or '' when the browser can't tell. */
function currentOffset(zone: string): string {
    try {
        return new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'shortOffset' })
            .formatToParts(new Date())
            .find((part) => part.type === 'timeZoneName')?.value ?? '';
    } catch {
        return '';
    }
}

const label = (zone: string) => zone.replace(/_/g, ' ');

/** Searchable picker over every IANA zone — a plain select can't scroll ~420 entries. */
export function TimezonePicker({ id, value, timezones, onChange }: {
    id?: string;
    value: string;
    timezones: string[];
    onChange: (zone: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const zones = useMemo(
        () => [...new Set([value, ...timezones].filter(Boolean))].sort().map((zone) => ({ zone, offset: currentOffset(zone) })),
        [timezones, value],
    );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button id={id} type="button" variant="outline" role="combobox" aria-expanded={open} className="h-11 w-full justify-between font-normal">
                    <span className="truncate">{value ? label(value) : 'Select timezone'}</span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[--radix-popover-trigger-width] min-w-72 p-0" align="start">
                <Command>
                    <CommandInput placeholder="Search city or region…" />
                    <CommandList className="max-h-[300px]">
                        <CommandEmpty>No timezone found.</CommandEmpty>
                        {zones.map(({ zone, offset }) => (
                            <CommandItem key={zone} value={`${zone} ${label(zone)} ${offset}`} onSelect={() => { onChange(zone); setOpen(false); }}>
                                <Check className={cn('size-4', zone === value ? 'opacity-100' : 'opacity-0')} />
                                <span className="truncate">{label(zone)}</span>
                                {offset && <span className="ml-auto shrink-0 text-xs text-muted-foreground">{offset}</span>}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
