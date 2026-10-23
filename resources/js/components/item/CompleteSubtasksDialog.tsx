import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

/**
 * Moving an issue to a done column while some of its subtasks (any level) are still open:
 * complete them too, or only the issue.
 */
export default function CompleteSubtasksDialog({
    open,
    itemKey,
    openCount,
    columnName,
    onChoose,
    onCancel,
}: {
    open: boolean;
    itemKey: string;
    openCount: number;
    columnName: string;
    onChoose: (withSubtasks: boolean) => void;
    onCancel: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onCancel()}>
            <DialogContent className="sm:max-w-md" onClick={(e) => e.stopPropagation()}>
                <DialogHeader>
                    <DialogTitle>
                        Move {itemKey} to {columnName}?
                    </DialogTitle>
                    <DialogDescription>
                        It has {openCount} open subtask{openCount === 1 ? '' : 's'}. Should they be completed as well?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button variant="outline" onClick={() => onChoose(false)}>
                        Only {itemKey}
                    </Button>
                    <Button onClick={() => onChoose(true)}>Complete all</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
