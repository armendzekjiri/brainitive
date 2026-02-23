import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import {
    $deleteTableColumn__EXPERIMENTAL,
    $deleteTableRow__EXPERIMENTAL,
    $getNodeTriplet,
    $getTableCellNodeFromLexicalNode,
    $getTableColumnIndexFromTableCellNode,
    $getTableNodeFromLexicalNodeOrThrow,
    $getTableRowIndexFromTableCellNode,
    $insertTableColumn__EXPERIMENTAL,
    $insertTableRow__EXPERIMENTAL,
    $isTableCellNode,
    $isTableRowNode,
    $isTableSelection,
    $unmergeCell,
    getTableObserverFromTableElement,
    HTMLTableElementWithWithTableSelectionState,
    TableCellHeaderStates,
    TableCellNode,
    TableRowNode,
    TableSelection,
} from '@lexical/table';
import {
    $createParagraphNode,
    $getRoot,
    $getSelection,
    $isParagraphNode,
    $isRangeSelection,
    $isTextNode,
} from 'lexical';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    Plus,
    Trash2,
    ArrowUp,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    Columns,
    Rows,
    Grid3X3,
} from 'lucide-react';

function TableCellActionMenuContainer({
    anchorElem,
    cellMerge,
}: {
    anchorElem: HTMLElement;
    cellMerge: boolean;
}) {
    const [editor] = useLexicalComposerContext();
    const menuButtonRef = useRef<HTMLDivElement>(null);
    const menuRootRef = useRef<HTMLDivElement>(null);
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [tableCellNode, setTableCellNode] = useState<TableCellNode | null>(null);
    const [selectionCounts, setSelectionCounts] = useState({
        columns: 1,
        rows: 1,
    });
    const [menuPosition, setMenuPosition] = useState({ x: 0, y: 0 });

    useEffect(() => {
        return editor.registerUpdateListener(() => {
            editor.getEditorState().read(() => {
                const selection = $getSelection();
                const nativeSelection = window.getSelection();
                const activeElement = document.activeElement;

                if ($isRangeSelection(selection)) {
                    const anchorNode = selection.anchor.getNode();
                    const tableCellNodeFromSelection = $getTableCellNodeFromLexicalNode(anchorNode);

                    if (tableCellNodeFromSelection) {
                        const tableCellElement = editor.getElementByKey(tableCellNodeFromSelection.getKey());
                        if (tableCellElement) {
                            const rect = tableCellElement.getBoundingClientRect();
                            setMenuPosition({
                                x: rect.right - 24,
                                y: rect.top + 4,
                            });
                        }
                        setTableCellNode(tableCellNodeFromSelection);
                    } else {
                        setTableCellNode(null);
                    }
                } else if ($isTableSelection(selection)) {
                    const nodes = selection.getNodes();
                    const tableCells = nodes.filter($isTableCellNode);
                    if (tableCells.length > 0) {
                        setTableCellNode(tableCells[0]);

                        // Count selection
                        const rowSet = new Set<string>();
                        const columnSet = new Set<number>();
                        tableCells.forEach(cell => {
                            const parent = cell.getParent();
                            if ($isTableRowNode(parent)) {
                                rowSet.add(parent.getKey());
                            }
                            columnSet.add($getTableColumnIndexFromTableCellNode(cell));
                        });
                        setSelectionCounts({
                            columns: columnSet.size,
                            rows: rowSet.size,
                        });
                    }
                }
            });
        });
    }, [editor]);

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (
                menuRootRef.current &&
                !menuRootRef.current.contains(event.target as Node) &&
                menuButtonRef.current &&
                !menuButtonRef.current.contains(event.target as Node)
            ) {
                setIsMenuOpen(false);
            }
        };

        if (isMenuOpen) {
            document.addEventListener('click', handleClickOutside);
            return () => document.removeEventListener('click', handleClickOutside);
        }
    }, [isMenuOpen]);

    const insertTableRowAbove = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                $insertTableRow__EXPERIMENTAL(false);
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    const insertTableRowBelow = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                $insertTableRow__EXPERIMENTAL(true);
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    const insertTableColumnBefore = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                $insertTableColumn__EXPERIMENTAL(false);
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    const insertTableColumnAfter = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                $insertTableColumn__EXPERIMENTAL(true);
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    const deleteTableRow = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                $deleteTableRow__EXPERIMENTAL();
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    const deleteTableColumn = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                $deleteTableColumn__EXPERIMENTAL();
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    const deleteTable = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
                tableNode.remove();
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    const toggleHeaderRow = useCallback(() => {
        editor.update(() => {
            if (tableCellNode) {
                const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
                const firstRow = tableNode.getFirstChild();
                if ($isTableRowNode(firstRow)) {
                    firstRow.getChildren().forEach(cell => {
                        if ($isTableCellNode(cell)) {
                            const headerState = cell.getHeaderStyles();
                            const newHeaderState = headerState === TableCellHeaderStates.ROW
                                ? TableCellHeaderStates.NO_STATUS
                                : TableCellHeaderStates.ROW;
                            cell.setHeaderStyles(newHeaderState);
                        }
                    });
                }
            }
        });
        setIsMenuOpen(false);
    }, [editor, tableCellNode]);

    if (!tableCellNode) {
        return null;
    }

    return (
        <>
            <div
                ref={menuButtonRef}
                className="absolute z-10"
                style={{
                    left: `${menuPosition.x}px`,
                    top: `${menuPosition.y}px`,
                }}
            >
                <button
                    onClick={() => setIsMenuOpen(!isMenuOpen)}
                    className="w-5 h-5 flex items-center justify-center rounded bg-muted hover:bg-muted/80 text-muted-foreground hover:text-foreground transition-colors"
                    title="Table actions"
                >
                    <Grid3X3 className="w-3 h-3" />
                </button>
            </div>

            {isMenuOpen && (
                <div
                    ref={menuRootRef}
                    className="fixed z-50 w-56 bg-popover border rounded-lg shadow-lg py-1"
                    style={{
                        left: `${menuPosition.x - 200}px`,
                        top: `${menuPosition.y + 24}px`,
                    }}
                >
                    <div className="px-2 py-1 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                        Rows
                    </div>
                    <button
                        onClick={insertTableRowAbove}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors"
                    >
                        <ArrowUp className="w-4 h-4" />
                        Insert row above
                    </button>
                    <button
                        onClick={insertTableRowBelow}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors"
                    >
                        <ArrowDown className="w-4 h-4" />
                        Insert row below
                    </button>
                    <button
                        onClick={deleteTableRow}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors text-red-500"
                    >
                        <Trash2 className="w-4 h-4" />
                        Delete row
                    </button>

                    <div className="border-t my-1" />

                    <div className="px-2 py-1 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                        Columns
                    </div>
                    <button
                        onClick={insertTableColumnBefore}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors"
                    >
                        <ArrowLeft className="w-4 h-4" />
                        Insert column before
                    </button>
                    <button
                        onClick={insertTableColumnAfter}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors"
                    >
                        <ArrowRight className="w-4 h-4" />
                        Insert column after
                    </button>
                    <button
                        onClick={deleteTableColumn}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors text-red-500"
                    >
                        <Trash2 className="w-4 h-4" />
                        Delete column
                    </button>

                    <div className="border-t my-1" />

                    <div className="px-2 py-1 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                        Table
                    </div>
                    <button
                        onClick={toggleHeaderRow}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors"
                    >
                        <Rows className="w-4 h-4" />
                        Toggle header row
                    </button>
                    <button
                        onClick={deleteTable}
                        className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted transition-colors text-red-500"
                    >
                        <Trash2 className="w-4 h-4" />
                        Delete table
                    </button>
                </div>
            )}
        </>
    );
}

export default function TableActionMenuPlugin({
    anchorElem = document.body,
    cellMerge = true,
}: {
    anchorElem?: HTMLElement;
    cellMerge?: boolean;
}) {
    return createPortal(
        <TableCellActionMenuContainer
            anchorElem={anchorElem}
            cellMerge={cellMerge}
        />,
        anchorElem
    );
}
