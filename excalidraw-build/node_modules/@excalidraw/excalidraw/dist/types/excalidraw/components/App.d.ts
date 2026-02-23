import React from "react";
import type { RoughCanvas } from "roughjs/bin/canvas";
import { ActionManager } from "../actions/manager";
import type { ActionResult } from "../actions/types";
import { type EXPORT_IMAGE_TYPES } from "../constants";
import type { ExportedElements } from "../data";
import { LinearElementEditor } from "../element/linearElementEditor";
import type { ExcalidrawElement, NonDeleted, InitializedExcalidrawImageElement, ExcalidrawImageElement, NonDeletedExcalidrawElement, ExcalidrawFrameLikeElement, ExcalidrawIframeElement, ExcalidrawEmbeddableElement, Ordered } from "../element/types";
import { History } from "../history";
import Scene from "../scene/Scene";
import type { AppClassProperties, AppProps, AppState, ExcalidrawImperativeAPI, BinaryFiles, LibraryItems, SceneData, Device, FrameNameBoundsCache, SidebarName, SidebarTabName, ToolType, OnUserFollowedPayload, GenerateDiagramToCode, NullableGridSize, Offsets } from "../types";
import type { FileSystemHandle } from "../data/filesystem";
import { Fonts } from "../fonts";
import { Renderer } from "../scene/Renderer";
import { Emitter } from "../emitter";
import { Store } from "../store";
import { AnimationFrameHandler } from "../animation-frame-handler";
import { AnimatedTrail } from "../animated-trail";
import { LaserTrails } from "../laser-trails";
import { FlowChartCreator } from "../element/flowchart";
export declare const ExcalidrawContainerContext: React.Context<{
    container: HTMLDivElement | null;
    id: string | null;
}>;
export declare const useApp: () => AppClassProperties;
export declare const useAppProps: () => AppProps;
export declare const useDevice: () => Readonly<{
    viewport: {
        isMobile: boolean;
        isLandscape: boolean;
    };
    editor: {
        isMobile: boolean;
        canFitSidebar: boolean;
    };
    isTouchScreen: boolean;
}>;
export declare const useExcalidrawContainer: () => {
    container: HTMLDivElement | null;
    id: string | null;
};
export declare const useExcalidrawElements: () => readonly NonDeletedExcalidrawElement[];
export declare const useExcalidrawAppState: () => AppState;
export declare const useExcalidrawSetAppState: () => <K extends keyof AppState>(state: AppState | ((prevState: Readonly<AppState>, props: Readonly<any>) => AppState | Pick<AppState, K> | null) | Pick<AppState, K> | null, callback?: (() => void) | undefined) => void;
export declare const useExcalidrawActionManager: () => ActionManager;
declare class App extends React.Component<AppProps, AppState> {
    canvas: AppClassProperties["canvas"];
    interactiveCanvas: AppClassProperties["interactiveCanvas"];
    rc: RoughCanvas;
    unmounted: boolean;
    actionManager: ActionManager;
    device: Device;
    private excalidrawContainerRef;
    scene: Scene;
    fonts: Fonts;
    renderer: Renderer;
    visibleElements: readonly NonDeletedExcalidrawElement[];
    private resizeObserver;
    private nearestScrollableContainer;
    library: AppClassProperties["library"];
    libraryItemsFromStorage: LibraryItems | undefined;
    id: string;
    private store;
    private history;
    excalidrawContainerValue: {
        container: HTMLDivElement | null;
        id: string;
    };
    files: BinaryFiles;
    imageCache: AppClassProperties["imageCache"];
    private iFrameRefs;
    /**
     * Indicates whether the embeddable's url has been validated for rendering.
     * If value not set, indicates that the validation is pending.
     * Initially or on url change the flag is not reset so that we can guarantee
     * the validation came from a trusted source (the editor).
     **/
    private embedsValidationStatus;
    /** embeds that have been inserted to DOM (as a perf optim, we don't want to
     * insert to DOM before user initially scrolls to them) */
    private initializedEmbeds;
    private elementsPendingErasure;
    flowChartCreator: FlowChartCreator;
    private flowChartNavigator;
    hitLinkElement?: NonDeletedExcalidrawElement;
    lastPointerDownEvent: React.PointerEvent<HTMLElement> | null;
    lastPointerUpEvent: React.PointerEvent<HTMLElement> | PointerEvent | null;
    lastPointerMoveEvent: PointerEvent | null;
    lastPointerMoveCoords: {
        x: number;
        y: number;
    } | null;
    lastViewportPosition: {
        x: number;
        y: number;
    };
    animationFrameHandler: AnimationFrameHandler;
    laserTrails: LaserTrails;
    eraserTrail: AnimatedTrail;
    onChangeEmitter: Emitter<[elements: readonly ExcalidrawElement[], appState: AppState, files: BinaryFiles]>;
    onPointerDownEmitter: Emitter<[activeTool: {
        lastActiveTool: import("../types").ActiveTool | null;
        locked: boolean;
    } & import("../types").ActiveTool, pointerDownState: Readonly<{
        origin: Readonly<{
            x: number;
            y: number;
        }>;
        originInGrid: Readonly<{
            x: number;
            y: number;
        }>;
        scrollbars: {
            isOverEither: boolean;
            isOverHorizontal: boolean;
            isOverVertical: boolean;
        };
        lastCoords: {
            x: number;
            y: number;
        };
        originalElements: Map<string, NonDeleted<ExcalidrawElement>>;
        resize: {
            handleType: import("../element/transformHandles").MaybeTransformHandleType;
            isResizing: boolean;
            offset: {
                x: number;
                y: number;
            };
            arrowDirection: "end" | "origin";
            center: {
                x: number;
                y: number;
            };
        };
        hit: {
            element: NonDeleted<ExcalidrawElement> | null;
            allHitElements: NonDeleted<ExcalidrawElement>[];
            wasAddedToSelection: boolean;
            hasBeenDuplicated: boolean;
            hasHitCommonBoundingBoxOfSelectedElements: boolean;
        };
        withCmdOrCtrl: boolean;
        drag: {
            hasOccurred: boolean;
            offset: {
                x: number;
                y: number;
            } | null;
        };
        eventListeners: {
            onMove: {
                (...args: any[]): void;
                flush(): void;
                cancel(): void;
            } | null;
            onUp: ((event: PointerEvent) => void) | null;
            onKeyDown: ((event: KeyboardEvent) => void) | null;
            onKeyUp: ((event: KeyboardEvent) => void) | null;
        };
        boxSelection: {
            hasOccurred: boolean;
        };
    }>, event: React.PointerEvent<HTMLElement>]>;
    onPointerUpEmitter: Emitter<[activeTool: {
        lastActiveTool: import("../types").ActiveTool | null;
        locked: boolean;
    } & import("../types").ActiveTool, pointerDownState: Readonly<{
        origin: Readonly<{
            x: number;
            y: number;
        }>;
        originInGrid: Readonly<{
            x: number;
            y: number;
        }>;
        scrollbars: {
            isOverEither: boolean;
            isOverHorizontal: boolean;
            isOverVertical: boolean;
        };
        lastCoords: {
            x: number;
            y: number;
        };
        originalElements: Map<string, NonDeleted<ExcalidrawElement>>;
        resize: {
            handleType: import("../element/transformHandles").MaybeTransformHandleType;
            isResizing: boolean;
            offset: {
                x: number;
                y: number;
            };
            arrowDirection: "end" | "origin";
            center: {
                x: number;
                y: number;
            };
        };
        hit: {
            element: NonDeleted<ExcalidrawElement> | null;
            allHitElements: NonDeleted<ExcalidrawElement>[];
            wasAddedToSelection: boolean;
            hasBeenDuplicated: boolean;
            hasHitCommonBoundingBoxOfSelectedElements: boolean;
        };
        withCmdOrCtrl: boolean;
        drag: {
            hasOccurred: boolean;
            offset: {
                x: number;
                y: number;
            } | null;
        };
        eventListeners: {
            onMove: {
                (...args: any[]): void;
                flush(): void;
                cancel(): void;
            } | null;
            onUp: ((event: PointerEvent) => void) | null;
            onKeyDown: ((event: KeyboardEvent) => void) | null;
            onKeyUp: ((event: KeyboardEvent) => void) | null;
        };
        boxSelection: {
            hasOccurred: boolean;
        };
    }>, event: PointerEvent]>;
    onUserFollowEmitter: Emitter<[payload: OnUserFollowedPayload]>;
    onScrollChangeEmitter: Emitter<[scrollX: number, scrollY: number, zoom: Readonly<{
        value: import("../types").NormalizedZoomValue;
    }>]>;
    missingPointerEventCleanupEmitter: Emitter<[event: PointerEvent | null]>;
    onRemoveEventListenersEmitter: Emitter<[]>;
    constructor(props: AppProps);
    private onWindowMessage;
    private cacheEmbeddableRef;
    /**
     * Returns gridSize taking into account `gridModeEnabled`.
     * If disabled, returns null.
     */
    getEffectiveGridSize: () => NullableGridSize;
    private getHTMLIFrameElement;
    private handleEmbeddableCenterClick;
    private isIframeLikeElementCenter;
    private updateEmbedValidationStatus;
    private updateEmbeddables;
    private renderEmbeddables;
    private getFrameNameDOMId;
    frameNameBoundsCache: FrameNameBoundsCache;
    private resetEditingFrame;
    private renderFrameNames;
    private toggleOverscrollBehavior;
    render(): import("react/jsx-runtime").JSX.Element;
    focusContainer: AppClassProperties["focusContainer"];
    getSceneElementsIncludingDeleted: () => readonly import("../element/types").OrderedExcalidrawElement[];
    getSceneElements: () => readonly Ordered<NonDeletedExcalidrawElement>[];
    onInsertElements: (elements: readonly ExcalidrawElement[]) => void;
    onExportImage: (type: keyof typeof EXPORT_IMAGE_TYPES, elements: ExportedElements, opts: {
        exportingFrame: ExcalidrawFrameLikeElement | null;
    }) => Promise<void>;
    private magicGenerations;
    private updateMagicGeneration;
    plugins: {
        diagramToCode?: {
            generate: GenerateDiagramToCode;
        };
    };
    setPlugins(plugins: Partial<App["plugins"]>): void;
    private onMagicFrameGenerate;
    private onIframeSrcCopy;
    onMagicframeToolSelect: () => void;
    private openEyeDropper;
    dismissLinearEditor: () => void;
    syncActionResult: (actionResult: ActionResult) => void;
    private onBlur;
    private onUnload;
    private disableEvent;
    private resetHistory;
    private resetStore;
    /**
     * Resets scene & history.
     * ! Do not use to clear scene user action !
     */
    private resetScene;
    private initializeScene;
    private isMobileBreakpoint;
    private refreshViewportBreakpoints;
    private refreshEditorBreakpoints;
    private clearImageShapeCache;
    componentDidMount(): Promise<void>;
    componentWillUnmount(): void;
    private onResize;
    /** generally invoked only if fullscreen was invoked programmatically */
    private onFullscreenChange;
    private removeEventListeners;
    private addEventListeners;
    componentDidUpdate(prevProps: AppProps, prevState: AppState): void;
    private renderInteractiveSceneCallback;
    private onScroll;
    private onCut;
    private onCopy;
    private static resetTapTwice;
    private onTouchStart;
    private onTouchEnd;
    pasteFromClipboard: (event: ClipboardEvent) => Promise<void>;
    addElementsFromPasteOrLibrary: (opts: {
        elements: readonly ExcalidrawElement[];
        files: BinaryFiles | null;
        position: {
            clientX: number;
            clientY: number;
        } | "cursor" | "center";
        retainSeed?: boolean;
        fitToContent?: boolean;
    }) => void;
    private addElementsFromMixedContentPaste;
    private addTextFromPaste;
    setAppState: React.Component<any, AppState>["setState"];
    removePointer: (event: React.PointerEvent<HTMLElement> | PointerEvent) => void;
    toggleLock: (source?: "keyboard" | "ui") => void;
    updateFrameRendering: (opts: Partial<{
        enabled: boolean;
        name: boolean;
        outline: boolean;
        clip: boolean;
    }> | ((prevState: AppState["frameRendering"]) => Partial<AppState["frameRendering"]>)) => void;
    togglePenMode: (force: boolean | null) => void;
    onHandToolToggle: () => void;
    /**
     * Zooms on canvas viewport center
     */
    zoomCanvas: (value: number) => void;
    private cancelInProgressAnimation;
    scrollToContent: (target?: string | ExcalidrawElement | readonly ExcalidrawElement[], opts?: ({
        fitToContent?: boolean;
        fitToViewport?: never;
        viewportZoomFactor?: number;
        animate?: boolean;
        duration?: number;
    } | {
        fitToContent?: never;
        fitToViewport?: boolean;
        /** when fitToViewport=true, how much screen should the content cover,
         * between 0.1 (10%) and 1 (100%)
         */
        viewportZoomFactor?: number;
        animate?: boolean;
        duration?: number;
    }) & {
        minZoom?: number;
        maxZoom?: number;
        canvasOffsets?: Offsets;
    }) => void;
    private maybeUnfollowRemoteUser;
    /** use when changing scrollX/scrollY/zoom based on user interaction */
    private translateCanvas;
    setToast: (toast: {
        message: string;
        closable?: boolean;
        duration?: number;
    } | null) => void;
    restoreFileFromShare: () => Promise<void>;
    /**
     * adds supplied files to existing files in the appState.
     * NOTE if file already exists in editor state, the file data is not updated
     * */
    addFiles: ExcalidrawImperativeAPI["addFiles"];
    private addMissingFiles;
    updateScene: <K extends keyof AppState>(sceneData: {
        elements?: SceneData["elements"];
        appState?: Pick<AppState, K> | null | undefined;
        collaborators?: SceneData["collaborators"];
        /**
         *  Controls which updates should be captured by the `Store`. Captured updates are emmitted and listened to by other components, such as `History` for undo / redo purposes.
         *
         *  - `CaptureUpdateAction.IMMEDIATELY`: Updates are immediately undoable. Use for most local updates.
         *  - `CaptureUpdateAction.NEVER`: Updates never make it to undo/redo stack. Use for remote updates or scene initialization.
         *  - `CaptureUpdateAction.EVENTUALLY`: Updates will be eventually be captured as part of a future increment.
         *
         * Check [API docs](https://docs.excalidraw.com/docs/@excalidraw/excalidraw/api/props/excalidraw-api#captureUpdate) for more details.
         *
         * @default CaptureUpdateAction.EVENTUALLY
         */
        captureUpdate?: SceneData["captureUpdate"];
    }) => void;
    private triggerRender;
    /**
     * @returns whether the menu was toggled on or off
     */
    toggleSidebar: ({ name, tab, force, }: {
        name: SidebarName | null;
        tab?: string | undefined;
        force?: boolean | undefined;
    }) => boolean;
    private updateCurrentCursorPosition;
    getEditorUIOffsets: () => Offsets;
    private onKeyDown;
    private onKeyUp;
    private isToolSupported;
    setActiveTool: (tool: (({
        type: Exclude<ToolType, "image">;
    } | {
        type: Extract<ToolType, "image">;
        insertOnCanvasDirectly?: boolean;
    }) | {
        type: "custom";
        customType: string;
    }) & {
        locked?: boolean;
    }) => void;
    setOpenDialog: (dialogType: AppState["openDialog"]) => void;
    private setCursor;
    private resetCursor;
    /**
     * returns whether user is making a gesture with >= 2 fingers (points)
     * on o touch screen (not on a trackpad). Currently only relates to Darwin
     * (iOS/iPadOS,MacOS), but may work on other devices in the future if
     * GestureEvent is standardized.
     */
    private isTouchScreenMultiTouchGesture;
    getName: () => string;
    private onGestureStart;
    private onGestureChange;
    private onGestureEnd;
    private handleTextWysiwyg;
    private deselectElements;
    private getTextElementAtPosition;
    private getElementAtPosition;
    private getElementsAtPosition;
    private getElementHitThreshold;
    private hitElement;
    private getTextBindableContainerAtPosition;
    private startTextEditing;
    private startImageCropping;
    private finishImageCropping;
    private handleCanvasDoubleClick;
    private getElementLinkAtPosition;
    private redirectToLink;
    private getTopLayerFrameAtSceneCoords;
    private handleCanvasPointerMove;
    private handleEraser;
    private handleTouchMove;
    handleHoverSelectedLinearElement(linearElementEditor: LinearElementEditor, scenePointerX: number, scenePointerY: number): void;
    private handleCanvasPointerDown;
    private handleCanvasPointerUp;
    private maybeOpenContextMenuAfterPointerDownOnTouchDevices;
    private resetContextMenuTimer;
    /**
     * pointerup may not fire in certian cases (user tabs away...), so in order
     * to properly cleanup pointerdown state, we need to fire any hanging
     * pointerup handlers manually
     */
    private maybeCleanupAfterMissingPointerUp;
    handleCanvasPanUsingWheelOrSpaceDrag: (event: React.PointerEvent<HTMLElement> | MouseEvent) => boolean;
    private updateGestureOnPointerDown;
    private initialPointerDownState;
    private handleDraggingScrollBar;
    private clearSelectionIfNotUsingSelection;
    /**
     * @returns whether the pointer event has been completely handled
     */
    private handleSelectionOnPointerDown;
    private isASelectedElement;
    private isHittingCommonBoundingBoxOfSelectedElements;
    private handleTextOnPointerDown;
    private handleFreeDrawElementOnPointerDown;
    insertIframeElement: ({ sceneX, sceneY, width, height, }: {
        sceneX: number;
        sceneY: number;
        width: number;
        height: number;
    }) => NonDeleted<ExcalidrawIframeElement>;
    insertEmbeddableElement: ({ sceneX, sceneY, link, }: {
        sceneX: number;
        sceneY: number;
        link: string;
    }) => NonDeleted<ExcalidrawEmbeddableElement> | undefined;
    private createImageElement;
    private handleLinearElementOnPointerDown;
    private getCurrentItemRoundness;
    private createGenericElementOnPointerDown;
    private createFrameElementOnPointerDown;
    private maybeCacheReferenceSnapPoints;
    private maybeCacheVisibleGaps;
    private onKeyDownFromPointerDownHandler;
    private onKeyUpFromPointerDownHandler;
    private onPointerMoveFromPointerDownHandler;
    private handlePointerMoveOverScrollbars;
    private onPointerUpFromPointerDownHandler;
    private restoreReadyToEraseElements;
    private eraseElements;
    private initializeImage;
    /**
     * inserts image into elements array and rerenders
     */
    insertImageElement: (imageElement: ExcalidrawImageElement, imageFile: File, showCursorImagePreview?: boolean) => Promise<NonDeleted<InitializedExcalidrawImageElement> | null | undefined>;
    private setImagePreviewCursor;
    private onImageAction;
    initializeImageDimensions: (imageElement: ExcalidrawImageElement, forceNaturalSize?: boolean) => void;
    /** updates image cache, refreshing updated elements and/or setting status
        to error for images that fail during <img> element creation */
    private updateImageCache;
    /** adds new images to imageCache and re-renders if needed */
    private addNewImagesToImageCache;
    /** generally you should use `addNewImagesToImageCache()` directly if you need
     *  to render new images. This is just a failsafe  */
    private scheduleImageRefresh;
    private updateBindingEnabledOnPointerMove;
    private maybeSuggestBindingAtCursor;
    private maybeSuggestBindingsForLinearElementAtCoords;
    private clearSelection;
    private handleInteractiveCanvasRef;
    private handleAppOnDrop;
    loadFileToCanvas: (file: File, fileHandle: FileSystemHandle | null) => Promise<void>;
    private handleCanvasContextMenu;
    private maybeDragNewGenericElement;
    private maybeHandleCrop;
    private maybeHandleResize;
    private getContextMenuItems;
    private handleWheel;
    private getTextWysiwygSnappedToCenterPosition;
    private savePointer;
    private resetShouldCacheIgnoreZoomDebounced;
    private updateDOMRect;
    refresh: () => void;
    private getCanvasOffsets;
    private updateLanguage;
}
declare global {
    interface Window {
        h: {
            scene: Scene;
            elements: readonly ExcalidrawElement[];
            state: AppState;
            setState: React.Component<any, AppState>["setState"];
            app: InstanceType<typeof App>;
            history: History;
            store: Store;
        };
    }
}
export declare const createTestHook: () => void;
export default App;
