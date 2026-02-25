/**
 * Table column/row resize plugin — closely modelled on the Lexical playground
 * packages/lexical-playground/src/plugins/TableCellResizer/index.tsx
 */

import type { TableCellNode, TableDOMCell, TableMapType } from '@lexical/table';
import type { LexicalEditor, NodeKey } from 'lexical';
import type { CSSProperties, PointerEventHandler, ReactPortal } from 'react';

import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { useLexicalEditable } from '@lexical/react/useLexicalEditable';
import {
    $computeTableMapSkipCellCheck,
    $getTableNodeFromLexicalNodeOrThrow,
    $getTableRowIndexFromTableCellNode,
    $isTableCellNode,
    $isTableRowNode,
    getDOMCellFromTarget,
    getTableElement,
    TableNode,
} from '@lexical/table';
import { calculateZoomLevel, mergeRegister } from '@lexical/utils';
import {
    $getNearestNodeFromDOMNode,
    isHTMLElement,
    SKIP_SCROLL_INTO_VIEW_TAG,
} from 'lexical';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

type PointerPosition = { x: number; y: number };
type PointerDraggingDirection = 'right' | 'bottom';

const MIN_ROW_HEIGHT = 33;
const MIN_COLUMN_WIDTH = 50;
const ACTIVE_RESIZER_COLOR = '#76b6ff';

function TableCellResizer({ editor }: { editor: LexicalEditor }) {
    const targetRef = useRef<HTMLElement | null>(null);
    const resizerRef = useRef<HTMLDivElement | null>(null);
    const tableRectRef = useRef<DOMRect | null>(null);
    const [hasTable, setHasTable] = useState(false);

    const pointerStartPosRef = useRef<PointerPosition | null>(null);
    const [pointerCurrentPos, updatePointerCurrentPos] = useState<PointerPosition | null>(null);

    const [activeCell, updateActiveCell] = useState<TableDOMCell | null>(null);
    const [draggingDirection, updateDraggingDirection] = useState<PointerDraggingDirection | null>(null);
    const [hoveredDirection, updateHoveredDirection] = useState<PointerDraggingDirection | null>(null);

    const resetState = useCallback(() => {
        updateActiveCell(null);
        targetRef.current = null;
        updateDraggingDirection(null);
        updateHoveredDirection(null);
        pointerStartPosRef.current = null;
        tableRectRef.current = null;
    }, []);

    // Register node transform to init colWidths proportionally, and mutation listener to track tables
    useEffect(() => {
        const tableKeys = new Set<NodeKey>();
        return mergeRegister(
            editor.registerMutationListener(TableNode, (nodeMutations) => {
                for (const [nodeKey, mutation] of nodeMutations) {
                    if (mutation === 'destroyed') {
                        tableKeys.delete(nodeKey);
                    } else {
                        tableKeys.add(nodeKey);
                    }
                }
                setHasTable(tableKeys.size > 0);
            }),
            editor.registerNodeTransform(TableNode, (tableNode) => {
                if (tableNode.getColWidths()) return tableNode;
                const numColumns = tableNode.getColumnCount();
                if (numColumns === 0) return tableNode;
                // Size columns to fill the editor, with sensible fallback
                const root = editor.getRootElement();
                const availableWidth = root
                    ? Math.max(root.clientWidth - 48, numColumns * MIN_COLUMN_WIDTH)  // 48px for editor padding
                    : numColumns * 150;
                const columnWidth = Math.max(MIN_COLUMN_WIDTH, Math.floor(availableWidth / numColumns));
                tableNode.setColWidths(Array(numColumns).fill(columnWidth));
                return tableNode;
            }),
        );
    }, [editor]);

    // Attach pointer listeners to root + resizer container
    useEffect(() => {
        if (!hasTable) return;

        const onPointerMove = (event: PointerEvent) => {
            const target = event.target;
            if (!isHTMLElement(target)) return;

            if (draggingDirection) {
                event.preventDefault();
                event.stopPropagation();
                updatePointerCurrentPos({ x: event.clientX, y: event.clientY });
                return;
            }
            if (resizerRef.current && resizerRef.current.contains(target)) return;

            if (targetRef.current !== target) {
                targetRef.current = target;
                const cell = getDOMCellFromTarget(target);
                if (cell && activeCell !== cell) {
                    editor.getEditorState().read(() => {
                        const tableCellNode = $getNearestNodeFromDOMNode(cell.elem);
                        if (!tableCellNode) return;
                        if (!$isTableCellNode(tableCellNode)) return;

                        const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
                        const tableElement = getTableElement(tableNode, editor.getElementByKey(tableNode.getKey()));
                        if (!tableElement) return;

                        targetRef.current = target;
                        tableRectRef.current = tableElement.getBoundingClientRect();
                        updateActiveCell(cell);
                    }, { editor });
                } else if (cell == null) {
                    resetState();
                }
            }
        };

        const onPointerDown = (event: PointerEvent) => {
            // touch: treat as move to snap activeCell
            if (event.pointerType === 'touch') onPointerMove(event);
        };

        const resizerContainer = resizerRef.current;
        resizerContainer?.addEventListener('pointermove', onPointerMove, { capture: true });

        const removeRootListener = editor.registerRootListener((rootElement, prevRootElement) => {
            prevRootElement?.removeEventListener('pointermove', onPointerMove);
            prevRootElement?.removeEventListener('pointerdown', onPointerDown);
            rootElement?.addEventListener('pointermove', onPointerMove);
            rootElement?.addEventListener('pointerdown', onPointerDown);
        });

        return () => {
            removeRootListener();
            resizerContainer?.removeEventListener('pointermove', onPointerMove);
        };
    }, [activeCell, draggingDirection, editor, resetState, hasTable]);

    const isHeightChanging = (direction: PointerDraggingDirection) => direction === 'bottom';

    // Update column width by changing colWidths on the TableNode
    const updateColumnWidth = useCallback((widthChange: number) => {
        if (!activeCell) return;
        editor.update(() => {
            const tableCellNode = $getNearestNodeFromDOMNode(activeCell.elem);
            if (!$isTableCellNode(tableCellNode)) return;

            const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
            const [tableMap] = $computeTableMapSkipCellCheck(tableNode, null, null);

            let columnIndex: number | undefined;
            outer: for (let row = 0; row < tableMap.length; row++) {
                for (let col = 0; col < tableMap[row].length; col++) {
                    if (tableMap[row][col].cell === tableCellNode) {
                        columnIndex = col;
                        break outer;
                    }
                }
            }
            if (columnIndex === undefined) return;

            const colWidths = tableNode.getColWidths();
            if (!colWidths) return;

            const width = colWidths[columnIndex];
            if (width === undefined) return;

            const newColWidths = [...colWidths];
            newColWidths[columnIndex] = Math.max(width + widthChange, MIN_COLUMN_WIDTH);
            tableNode.setColWidths(newColWidths);
        }, { tag: SKIP_SCROLL_INTO_VIEW_TAG });
    }, [activeCell, editor]);

    // Update row height by changing height on the TableRowNode
    const updateRowHeight = useCallback((heightChange: number) => {
        if (!activeCell) return;
        editor.update(() => {
            const tableCellNode = $getNearestNodeFromDOMNode(activeCell.elem);
            if (!$isTableCellNode(tableCellNode)) return;

            const tableNode = $getTableNodeFromLexicalNodeOrThrow(tableCellNode);
            const baseRowIndex = $getTableRowIndexFromTableCellNode(tableCellNode);
            const tableRows = tableNode.getChildren();

            const isFullRowMerge = tableCellNode.getColSpan() === tableNode.getColumnCount();
            const tableRowIndex = isFullRowMerge
                ? baseRowIndex
                : baseRowIndex + tableCellNode.getRowSpan() - 1;

            if (tableRowIndex >= tableRows.length || tableRowIndex < 0) return;

            const tableRow = tableRows[tableRowIndex];
            if (!$isTableRowNode(tableRow)) return;

            let height = tableRow.getHeight();
            if (height === undefined) {
                const domCell = editor.getElementByKey(tableCellNode.getKey());
                height = domCell?.clientHeight ?? MIN_ROW_HEIGHT;
            }

            tableRow.setHeight(Math.max(height + heightChange, MIN_ROW_HEIGHT));
        }, { tag: SKIP_SCROLL_INTO_VIEW_TAG });
    }, [activeCell, editor]);

    const pointerUpHandler = useCallback((direction: PointerDraggingDirection) => {
        const handler = (event: PointerEvent) => {
            event.preventDefault();
            event.stopPropagation();
            if (!activeCell || !pointerStartPosRef.current) return;

            const { x, y } = pointerStartPosRef.current;
            const zoom = calculateZoomLevel(event.target as Element);

            if (isHeightChanging(direction)) {
                updateRowHeight((event.clientY - y) / zoom);
            } else {
                updateColumnWidth((event.clientX - x) / zoom);
            }

            resetState();
            document.removeEventListener('pointerup', handler);
        };
        return handler;
    }, [activeCell, resetState, updateColumnWidth, updateRowHeight]);

    const toggleResize = useCallback(
        (direction: PointerDraggingDirection): PointerEventHandler<HTMLDivElement> =>
            (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (!activeCell) return;

                pointerStartPosRef.current = { x: event.clientX, y: event.clientY };
                updatePointerCurrentPos(pointerStartPosRef.current);
                updateDraggingDirection(direction);

                document.addEventListener('pointerup', pointerUpHandler(direction));
            },
        [activeCell, pointerUpHandler],
    );

    const handlePointerEnter = useCallback(
        (direction: PointerDraggingDirection): PointerEventHandler<HTMLDivElement> =>
            () => {
                if (!draggingDirection) updateHoveredDirection(direction);
            },
        [draggingDirection],
    );

    const handlePointerLeave = useCallback(() => {
        if (!draggingDirection) updateHoveredDirection(null);
    }, [draggingDirection]);

    // Compute the four possible resizer handle styles
    const getResizers = useCallback(() => {
        if (!activeCell) return { bottom: null, right: null };

        const { height, width, top, left } = activeCell.elem.getBoundingClientRect();
        const zoom = calculateZoomLevel(activeCell.elem);
        const zoneWidth = 16;
        const tableRect = tableRectRef.current;

        const styles: Record<string, CSSProperties | null> = {
            bottom: {
                backgroundColor: 'transparent',
                cursor: 'row-resize',
                height: `${zoneWidth}px`,
                left: `${window.scrollX + left}px`,
                top: `${window.scrollY + top + height - zoneWidth / 2}px`,
                width: `${width}px`,
            },
            right: {
                backgroundColor: 'transparent',
                cursor: 'col-resize',
                height: `${height}px`,
                left: `${window.scrollX + left + width - zoneWidth / 2}px`,
                top: `${window.scrollY + top}px`,
                width: `${zoneWidth}px`,
            },
        };

        if (draggingDirection && pointerCurrentPos && tableRect) {
            if (isHeightChanging(draggingDirection)) {
                styles[draggingDirection] = {
                    ...styles[draggingDirection],
                    backgroundColor: ACTIVE_RESIZER_COLOR,
                    mixBlendMode: 'unset',
                    height: '3px',
                    left: `${window.scrollX + tableRect.left}px`,
                    top: `${window.scrollY + pointerCurrentPos.y / zoom}px`,
                    width: `${tableRect.width}px`,
                };
            } else {
                styles[draggingDirection] = {
                    ...styles[draggingDirection],
                    backgroundColor: ACTIVE_RESIZER_COLOR,
                    mixBlendMode: 'unset',
                    width: '3px',
                    left: `${window.scrollX + pointerCurrentPos.x / zoom}px`,
                    top: `${window.scrollY + tableRect.top}px`,
                    height: `${tableRect.height}px`,
                };
            }
        } else if (!draggingDirection && hoveredDirection === 'right') {
            const halfZone = zoneWidth / 2;
            const lineW = 2;
            const lineStart = halfZone - lineW / 2;
            styles.right = {
                ...styles.right,
                backgroundImage: `linear-gradient(90deg, transparent ${lineStart}px, ${ACTIVE_RESIZER_COLOR} ${lineStart}px, ${ACTIVE_RESIZER_COLOR} ${lineStart + lineW}px, transparent ${lineStart + lineW}px)`,
                mixBlendMode: 'unset',
                ...(tableRect && {
                    top: `${window.scrollY + tableRect.top}px`,
                    height: `${tableRect.height}px`,
                }),
            };
        }

        return styles;
    }, [activeCell, draggingDirection, hoveredDirection, pointerCurrentPos]);

    const resizerStyles = getResizers();

    return (
        <div ref={resizerRef}>
            {activeCell != null && (
                <>
                    <div
                        className="TableCellResizer__resizer TableCellResizer__ui"
                        style={resizerStyles.right ?? undefined}
                        onPointerEnter={handlePointerEnter('right')}
                        onPointerLeave={handlePointerLeave}
                        onPointerDown={toggleResize('right')}
                    />
                    <div
                        className="TableCellResizer__resizer TableCellResizer__ui"
                        style={resizerStyles.bottom ?? undefined}
                        onPointerDown={toggleResize('bottom')}
                    />
                </>
            )}
        </div>
    );
}

export default function TableCellResizerPlugin(): ReactPortal | null {
    const [editor] = useLexicalComposerContext();
    const isEditable = useLexicalEditable();

    return useMemo(
        () =>
            isEditable
                ? createPortal(<TableCellResizer editor={editor} />, document.body)
                : null,
        [editor, isEditable],
    );
}
