import { ChangeEvent, useMemo, useState } from 'react';
import { FileUp } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { parseTable, rowsFromTable } from './csv';
import { PlanRow } from './types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hasRows: boolean;
    onImport: (rows: PlanRow[], mode: 'append' | 'replace') => void;
}

/**
 * Import rows from a CSV / TSV file or text pasted from Excel. Columns are matched by
 * header (Name, Start, End, Who, Status, Notes, Level…); leading spaces indent.
 */
export function ImportDialog({ open, onOpenChange, hasRows, onImport }: Props) {
    const [text, setText] = useState('');
    const rows = useMemo(() => (text.trim() ? rowsFromTable(parseTable(text)) : []), [text]);
    const withDates = rows.filter((r) => r.bars.length > 0).length;

    const onFile = async (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (file) setText(await file.text());
        event.target.value = '';
    };

    const submit = (mode: 'append' | 'replace') => {
        if (!rows.length) return;
        onImport(rows, mode);
        setText('');
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Import rows</DialogTitle>
                    <DialogDescription>Choose a CSV file or paste cells from Excel / Google Sheets. With a header row, columns like Name, Start, End, Who, Status and Notes are recognised; without one the columns are Name, Start, End.</DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                    <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-5 text-sm text-muted-foreground transition-colors hover:border-primary hover:text-foreground">
                        <FileUp className="size-4" />Choose a .csv or .txt file
                        <input type="file" accept=".csv,.tsv,.txt,text/csv,text/plain" className="sr-only" onChange={onFile} />
                    </label>
                    <Textarea
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        placeholder={'Name\tStart\tEnd\nDesign\t02.11.2026\t13.11.2026\n  Wireframes\t02.11.2026\t06.11.2026'}
                        rows={8}
                        className="font-mono text-xs"
                        aria-label="Rows to import"
                    />
                    <p className="text-sm text-muted-foreground" aria-live="polite">
                        {rows.length ? `${rows.length} row${rows.length === 1 ? '' : 's'} found, ${withDates} with dates.` : 'Nothing to import yet.'}
                    </p>
                </div>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    {hasRows && <Button variant="outline" disabled={!rows.length} onClick={() => submit('replace')}>Replace all rows</Button>}
                    <Button disabled={!rows.length} onClick={() => submit('append')}>{hasRows ? 'Add to plan' : 'Import'}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
