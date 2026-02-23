import type { ReactNode } from "react";
import React from "react";
import type { LibraryItem } from "../types";
import type { ExcalidrawElement, NonDeleted } from "../element/types";
import type { SvgCache } from "../hooks/useLibraryItemSvg";
type LibraryOrPendingItem = (LibraryItem | /* pending library item */ {
    id: null;
    elements: readonly NonDeleted<ExcalidrawElement>[];
})[];
interface Props {
    items: LibraryOrPendingItem;
    onClick: (id: LibraryItem["id"] | null) => void;
    onItemSelectToggle: (id: LibraryItem["id"], event: React.MouseEvent) => void;
    onItemDrag: (id: LibraryItem["id"], event: React.DragEvent) => void;
    isItemSelected: (id: LibraryItem["id"] | null) => boolean;
    svgCache: SvgCache;
    itemsRenderedPerBatch: number;
}
export declare const LibraryMenuSectionGrid: ({ children, }: {
    children: ReactNode;
}) => import("react/jsx-runtime").JSX.Element;
export declare const LibraryMenuSection: React.MemoExoticComponent<({ items, onItemSelectToggle, onItemDrag, isItemSelected, onClick, svgCache, itemsRenderedPerBatch, }: Props) => import("react/jsx-runtime").JSX.Element>;
export {};
