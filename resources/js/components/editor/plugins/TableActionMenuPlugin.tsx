import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { useLexicalEditable } from '@lexical/react/useLexicalEditable';
import {
    $computeTableMapSkipCellCheck,
    $deleteTableColumnAtSelection,
    $deleteTableRowAtSelection,
    $getNodeTriplet,
    $getTableCellNodeFromLexicalNode,
    $getTableColumnIndexFromTableCellNode,
    $getTableNodeFromLexicalNodeOrThrow,
    $getTableRowIndexFromTableCellNode,
    $insertTableColumnAtSelection,
    $insertTableRowAtSelection,
    $isTableCellNode,
    $isTableRowNode,
    $isTableSelection,
    $mergeCells,
    $unmergeCell,
    getTableElement,
    getTableObserverFromTableElement,
    TableCellHeaderStates,
    TableCellNode,
    TableObserver,
    TableSelection,
} from '@lexical/table';
import { mergeRegister } from '@lexical/utils';
import {
    $getSelection,
    $isRangeSelection,
    $setSelection,
    COMMAND_PRIORITY_CRITICAL,
    SELECTION_CHANGE_COMMAND,
} from 'lexical';
import { ReactPortal, useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    ChevronDown,
    Columns,
    Merge,
    Rows,
    Scissors,
    Trash2,
} from 'lucide-react';

// ─── helpers ────────────────────────────────────────────────────────────────

function computeSelectionCount(selection: TableSelection): { columns: number; rows: number } {
    const shape = selection.getShape();
    return { columns: shape.toX - shape.fromX + 1, rows: shape.toY - shape.fromY + 1 };
}

function $canUnmerge(): boolean {
    const selection = $getSelection();
    if (
        ($isRangeSelection(selection) && !selection.isCollapsed()) ||
        ($isTableSelection(selection) && !selection.anchor.is(selection.focus)) ||
        (!$isRangeSelection(selection) && !$isTableSelection(selection))
    ) {
        return false;
    }
    const [cell] = $getNodeTriplet(selection.anchor);
    return cell.__colSpan > 1 || cell.__rowSpan > 1;
}

// ─── dropdown menu ──────────────────────────────────────────────────────────

type TableActionMenuProps = {
    contextRef: React.RefObject<HTMLButtonElement | null>;
    tableCellNode: TableCellNode;
    cellMerge: boolean;
    onClose: () => void;
};

function TableActionMenu({ contextRef, tableCellNode: _tableCellNode, cellMerge, onClose }: TableActionMenuProps) {
    const [editor] = useLexicalComposerContext();
    const dropDownRef = useRef<HTMLDivElement | null>(null);
    const [tableCellNode, setTableCellNode] = useState(_tableCellNode);
    const [selectionCounts, setSelectionCounts] = useState({ columns: 1, rows: 1 });
    const [canMergeCells, setCanMergeCells] = useState(false);
    const [canUnmergeCell, setCanUnmergeCell] = useState(false);

    useEffect(() => {
        return editor.registerMutationListener(TableCellNode, (mutations) => {
            if (mutations.get(tableCellNode.getKey()) === 'updated') {
                editor.getEditorState().read(() => setTableCellNode((n) => n.getLatest()));
            }
        });
    }, [editor, tableCellNode]);

    useEffect(() => {
        editor.getEditorState().read(() => {
            const sel = $getSelection();
            if ($isTableSelection(sel)) {
                const counts = computeSelectionCount(sel);
                setSelectionCounts(counts);
                setCanMergeCells(counts.columns > 1 || counts.rows > 1);
            }
            setCanUnmergeCell($canUnmerge());
        });
    }, [editor]);

    // Position dropdown next to button, keeping inside viewport
    useEffect(() => {
        const btn = contextRef.current;
        const drop = dropDownRef.current;
        if (!btn || !drop) return;
        const btnRect = btn.getBoundingClientRect();
        const dropRect = drop.getBoundingClientRect();
        const margin = 5;
        let left = btnRect.right + margin;
        if (left + dropRect.width > window.innerWidth) left = btnRect.left - dropRect.width - margin;
        let top = btnRect.top;
        if (top + dropRect.height > window.innerHeight) top = btnRect.bottom - dropRect.height;
        drop.style.left = `${Math.max(margin, left)}px`;
        drop.style.top = `${Math.max(margin, top)}px`;
    }, [contextRef]);

    useEffect(() => {
        const handleClick = (e: MouseEvent) => {
            const target = e.target as Node;
            if (
                dropDownRef.current && !dropDownRef.current.contains(target) &&
                contextRef.current && !contextRef.current.contains(target)
            ) onClose();
        };
        window.addEventListener('click', handleClick);
        return () => window.removeEventListener('click', handleClick);
    }, [onClose, contextRef]);

    const clearSelection = useCallback(() => {
        editor.update(() => {
            if (tableCellNode.isAttached()) {
                const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
                const tableEl = getTableElement(tableNode, editor.getElementByKey(tableNode.getKey()));
                const observer = tableEl ? getTableObserverFromTableElement(tableEl) : null;
                observer?.$clearHighlight();
                tableNode.markDirty();
            }
            $setSelection(null);
        });
    }, [editor, tableCellNode]);

    const close = useCallback(() => { clearSelection(); onClose(); }, [clearSelection, onClose]);

    const insertRowAbove = () => { editor.update(() => $insertTableRowAtSelection(false)); close(); };
    const insertRowBelow = () => { editor.update(() => $insertTableRowAtSelection(true)); close(); };
    const deleteRow = () => { editor.update(() => $deleteTableRowAtSelection()); close(); };
    const insertColBefore = () => { editor.update(() => $insertTableColumnAtSelection(false)); close(); };
    const insertColAfter = () => { editor.update(() => $insertTableColumnAtSelection(true)); close(); };
    const deleteCol = () => { editor.update(() => $deleteTableColumnAtSelection()); close(); };
    const deleteTable = () => { editor.update(() => $getTableNodeFromLexicalNodeOrThrow(tableCellNode).remove()); close(); };

    const mergeCells = () => {
        editor.update(() => {
            const sel = $getSelection();
            if (!$isTableSelection(sel)) return;
            $mergeCells(sel.getNodes().filter($isTableCellNode) as TableCellNode[]);
        });
        close();
    };
    const unmergeCells = () => { editor.update(() => $unmergeCell()); close(); };

    const toggleRowHeader = useCallback(() => {
        editor.update(() => {
            const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
            const rowIdx = $getTableRowIndexFromTableCellNode(tableCellNode);
            const [gridMap] = $computeTableMapSkipCellCheck(tableNode, null, null);
            const seen = new Set<TableCellNode>();
            const newStyle = tableCellNode.getHeaderStyles() ^ TableCellHeaderStates.ROW;
            for (let col = 0; col < gridMap[rowIdx].length; col++) {
                const mc = gridMap[rowIdx][col];
                if (mc?.cell && !seen.has(mc.cell)) { seen.add(mc.cell); mc.cell.setHeaderStyles(newStyle, TableCellHeaderStates.ROW); }
            }
        });
        close();
    }, [editor, tableCellNode, close]);

    const toggleColHeader = useCallback(() => {
        editor.update(() => {
            const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
            const colIdx = $getTableColumnIndexFromTableCellNode(tableCellNode);
            const [gridMap] = $computeTableMapSkipCellCheck(tableNode, null, null);
            const seen = new Set<TableCellNode>();
            const newStyle = tableCellNode.getHeaderStyles() ^ TableCellHeaderStates.COLUMN;
            for (let row = 0; row < gridMap.length; row++) {
                const mc = gridMap[row][colIdx];
                if (mc?.cell && !seen.has(mc.cell)) { seen.add(mc.cell); mc.cell.setHeaderStyles(newStyle, TableCellHeaderStates.COLUMN); }
            }
        });
        close();
    }, [editor, tableCellNode, close]);

    const rowHeaderActive = (tableCellNode.__headerState & TableCellHeaderStates.ROW) === TableCellHeaderStates.ROW;
    const colHeaderActive = (tableCellNode.__headerState & TableCellHeaderStates.COLUMN) === TableCellHeaderStates.COLUMN;
    const rows = selectionCounts.rows;
    const cols = selectionCounts.columns;

    return createPortal(
        <div
            ref={dropDownRef}
            className="fixed z-[300] w-56 bg-popover border rounded-lg shadow-xl py-1 text-sm"
            onClick={(e) => e.stopPropagation()}
        >
            {cellMerge && canMergeCells && (
                <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={mergeCells}>
                    <Merge className="w-4 h-4 shrink-0" /> Merge cells
                </button>
            )}
            {cellMerge && canUnmergeCell && (
                <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={unmergeCells}>
                    <Scissors className="w-4 h-4 shrink-0" /> Unmerge cells
                </button>
            )}
            {cellMerge && (canMergeCells || canUnmergeCell) && <hr className="my-1" />}

            <div className="px-3 py-1 text-xs font-medium text-muted-foreground uppercase tracking-wider">Rows</div>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={insertRowAbove}>
                <ArrowUp className="w-4 h-4 shrink-0" /> Insert {rows === 1 ? 'row' : `${rows} rows`} above
            </button>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={insertRowBelow}>
                <ArrowDown className="w-4 h-4 shrink-0" /> Insert {rows === 1 ? 'row' : `${rows} rows`} below
            </button>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors text-red-500" onClick={deleteRow}>
                <Trash2 className="w-4 h-4 shrink-0" /> Delete {rows === 1 ? 'row' : `${rows} rows`}
            </button>
            <hr className="my-1" />

            <div className="px-3 py-1 text-xs font-medium text-muted-foreground uppercase tracking-wider">Columns</div>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={insertColBefore}>
                <ArrowLeft className="w-4 h-4 shrink-0" /> Insert {cols === 1 ? 'column' : `${cols} columns`} left
            </button>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={insertColAfter}>
                <ArrowRight className="w-4 h-4 shrink-0" /> Insert {cols === 1 ? 'column' : `${cols} columns`} right
            </button>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors text-red-500" onClick={deleteCol}>
                <Trash2 className="w-4 h-4 shrink-0" /> Delete {cols === 1 ? 'column' : `${cols} columns`}
            </button>
            <hr className="my-1" />

            <div className="px-3 py-1 text-xs font-medium text-muted-foreground uppercase tracking-wider">Table</div>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={toggleRowHeader}>
                <Rows className="w-4 h-4 shrink-0" /> {rowHeaderActive ? 'Remove' : 'Add'} row header
            </button>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors" onClick={toggleColHeader}>
                <Columns className="w-4 h-4 shrink-0" /> {colHeaderActive ? 'Remove' : 'Add'} column header
            </button>
            <button type="button" className="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-muted transition-colors text-red-500" onClick={deleteTable}>
                <Trash2 className="w-4 h-4 shrink-0" /> Delete table
            </button>
        </div>,
        document.body,
    );
}

// ─── container ──────────────────────────────────────────────────────────────

function TableCellActionMenuContainer({
    anchorElem,
    cellMerge,
}: {
    anchorElem: HTMLElement;
    cellMerge: boolean;
}) {
    const [editor] = useLexicalComposerContext();
    const menuButtonRef = useRef<HTMLDivElement | null>(null);
    const menuRootRef = useRef<HTMLButtonElement | null>(null);
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [tableCellNode, setTableMenuCellNode] = useState<TableCellNode | null>(null);

    const $moveMenu = useCallback(() => {
        const menu = menuButtonRef.current;
        const selection = $getSelection();
        const nativeSelection = window.getSelection();
        const activeElement = document.activeElement;

        function disable() {
            if (menu) {
                menu.classList.remove('table-cell-action-button-container--active');
                menu.classList.add('table-cell-action-button-container--inactive');
            }
            setTableMenuCellNode(null);
        }

        if (selection == null || menu == null) return disable();

        const rootElement = editor.getRootElement();
        let tableObserver: TableObserver | null = null;
        let tableCellParentNodeDOM: HTMLElement | null = null;

        if (
            $isRangeSelection(selection) &&
            rootElement !== null &&
            nativeSelection !== null &&
            rootElement.contains(nativeSelection.anchorNode)
        ) {
            const cellFromSel = $getTableCellNodeFromLexicalNode(selection.anchor.getNode());
            if (!cellFromSel) return disable();

            tableCellParentNodeDOM = editor.getElementByKey(cellFromSel.getKey()) as HTMLElement | null;
            if (!tableCellParentNodeDOM || !cellFromSel.isAttached()) return disable();

            const tableNode = $getTableNodeFromLexicalNodeOrThrow(cellFromSel);
            const tableEl = getTableElement(tableNode, editor.getElementByKey(tableNode.getKey()));
            if (!tableEl) return disable();

            tableObserver = getTableObserverFromTableElement(tableEl);
            setTableMenuCellNode(cellFromSel);
        } else if ($isTableSelection(selection)) {
            const anchorNode = $getTableCellNodeFromLexicalNode(selection.anchor.getNode());
            if (!$isTableCellNode(anchorNode)) return disable();

            tableCellParentNodeDOM = editor.getElementByKey(anchorNode.getKey()) as HTMLElement | null;
            if (!tableCellParentNodeDOM) return disable();

            const tableNode = $getTableNodeFromLexicalNodeOrThrow(anchorNode);
            const tableEl = getTableElement(tableNode, editor.getElementByKey(tableNode.getKey()));
            if (!tableEl) return disable();

            tableObserver = getTableObserverFromTableElement(tableEl);
            setTableMenuCellNode(anchorNode);
        } else if (!activeElement) {
            return disable();
        }

        if (tableObserver === null || tableCellParentNodeDOM === null) return disable();

        const enabled = !tableObserver.isSelecting;
        menu.classList.toggle('table-cell-action-button-container--active', enabled);
        menu.classList.toggle('table-cell-action-button-container--inactive', !enabled);

        if (enabled) {
            const cellRect = tableCellParentNodeDOM.getBoundingClientRect();
            const anchorRect = anchorElem.getBoundingClientRect();
            // Place the button INSIDE the cell: right-align it so its right edge
            // sits 2 px from the cell's right border (button is 20px wide → offset 22px).
            // This prevents the last column from causing a horizontal scroll.
            const BUTTON_WIDTH = 20;
            const BUTTON_INSET = 2; // px gap from cell right border
            const x = cellRect.right - anchorRect.left - BUTTON_WIDTH - BUTTON_INSET;
            const y = cellRect.top - anchorRect.top;
            menu.style.transform = `translate(${x}px, ${y}px)`;
        }
    }, [editor, anchorElem]);

    useEffect(() => {
        let timeoutId: ReturnType<typeof setTimeout> | undefined;
        const callback = () => { timeoutId = undefined; editor.getEditorState().read($moveMenu); };
        const delayedCallback = () => { if (timeoutId === undefined) timeoutId = setTimeout(callback, 0); return false; };

        return mergeRegister(
            editor.registerUpdateListener(delayedCallback),
            editor.registerCommand(SELECTION_CHANGE_COMMAND, delayedCallback, COMMAND_PRIORITY_CRITICAL),
            editor.registerRootListener((rootElement, prevRootElement) => {
                prevRootElement?.removeEventListener('pointerup', delayedCallback);
                if (rootElement) { rootElement.addEventListener('pointerup', delayedCallback); delayedCallback(); }
            }),
            () => clearTimeout(timeoutId),
        );
    }, [editor, $moveMenu]);

    const prevTableCellDOM = useRef(tableCellNode);
    useEffect(() => {
        if (prevTableCellDOM.current !== tableCellNode) setIsMenuOpen(false);
        prevTableCellDOM.current = tableCellNode;
    }, [tableCellNode]);

    return (
        <div
            className="table-cell-action-button-container table-cell-action-button-container--inactive"
            ref={menuButtonRef}
        >
            {tableCellNode != null && (
                <>
                    <button
                        type="button"
                        className="table-cell-action-button"
                        onClick={(e) => { e.stopPropagation(); setIsMenuOpen((o) => !o); }}
                        ref={menuRootRef}
                        title="Table options"
                    >
                        <ChevronDown className="w-3 h-3" />
                    </button>
                    {isMenuOpen && (
                        <TableActionMenu
                            contextRef={menuRootRef}
                            onClose={() => setIsMenuOpen(false)}
                            tableCellNode={tableCellNode}
                            cellMerge={cellMerge}
                        />
                    )}
                </>
            )}
        </div>
    );
}

// ─── plugin export ───────────────────────────────────────────────────────────

export default function TableActionMenuPlugin({
    anchorElem = document.body,
    cellMerge = true,
}: {
    anchorElem?: HTMLElement;
    cellMerge?: boolean;
}): ReactPortal | null {
    const isEditable = useLexicalEditable();
    return createPortal(
        isEditable ? <TableCellActionMenuContainer anchorElem={anchorElem} cellMerge={cellMerge} /> : null,
        anchorElem,
    );
}
