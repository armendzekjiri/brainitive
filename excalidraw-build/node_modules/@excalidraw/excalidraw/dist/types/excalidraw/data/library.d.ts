import type { LibraryItems, ExcalidrawImperativeAPI, LibraryItemsSource, LibraryItems_anyVersion } from "../types";
import type App from "../components/App";
import type { ExcalidrawElement } from "../element/types";
import type { MaybePromise } from "../utility-types";
export type LibraryPersistedData = {
    libraryItems: LibraryItems;
};
export type LibraryAdatapterSource = "load" | "save";
export interface LibraryPersistenceAdapter {
    /**
     * Should load data that were previously saved into the database using the
     * `save` method. Should throw if saving fails.
     *
     * Will be used internally in multiple places, such as during save to
     * in order to reconcile changes with latest store data.
     */
    load(metadata: {
        /**
         * Indicates whether we're loading data for save purposes, or reading
         * purposes, in which case host app can implement more aggressive caching.
         */
        source: LibraryAdatapterSource;
    }): MaybePromise<{
        libraryItems: LibraryItems_anyVersion;
    } | null>;
    /** Should persist to the database as is (do no change the data structure). */
    save(libraryData: LibraryPersistedData): MaybePromise<void>;
}
export interface LibraryMigrationAdapter {
    /**
     * loads data from legacy data source. Returns `null` if no data is
     * to be migrated.
     */
    load(): MaybePromise<{
        libraryItems: LibraryItems_anyVersion;
    } | null>;
    /** clears entire storage afterwards */
    clear(): MaybePromise<void>;
}
export declare const libraryItemsAtom: import("jotai/vanilla/atom").PrimitiveAtom<{
    status: "loading" | "loaded";
    /** indicates whether library is initialized with library items (has gone
     * through at least one update). Used in UI. Specific to this atom only. */
    isInitialized: boolean;
    libraryItems: LibraryItems;
}> & {
    init: {
        status: "loading" | "loaded";
        /** indicates whether library is initialized with library items (has gone
         * through at least one update). Used in UI. Specific to this atom only. */
        isInitialized: boolean;
        libraryItems: LibraryItems;
    };
};
/** Merges otherItems into localItems. Unique items in otherItems array are
    sorted first. */
export declare const mergeLibraryItems: (localItems: LibraryItems, otherItems: LibraryItems) => LibraryItems;
declare class Library {
    /** latest libraryItems */
    private currLibraryItems;
    /** snapshot of library items since last onLibraryChange call */
    private prevLibraryItems;
    private app;
    constructor(app: App);
    private updateQueue;
    private getLastUpdateTask;
    private notifyListeners;
    /** call on excalidraw instance unmount */
    destroy: () => void;
    resetLibrary: () => Promise<LibraryItems>;
    /**
     * @returns latest cloned libraryItems. Awaits all in-progress updates first.
     */
    getLatestLibrary: () => Promise<LibraryItems>;
    updateLibrary: ({ libraryItems, prompt, merge, openLibraryMenu, defaultStatus, }: {
        libraryItems: LibraryItemsSource;
        merge?: boolean | undefined;
        prompt?: boolean | undefined;
        openLibraryMenu?: boolean | undefined;
        defaultStatus?: "published" | "unpublished" | undefined;
    }) => Promise<LibraryItems>;
    setLibrary: (libraryItems: LibraryItems | Promise<LibraryItems> | ((latestLibraryItems: LibraryItems) => LibraryItems | Promise<LibraryItems>)) => Promise<LibraryItems>;
}
export default Library;
export declare const distributeLibraryItemsOnSquareGrid: (libraryItems: LibraryItems) => ExcalidrawElement[];
export declare const validateLibraryUrl: (libraryUrl: string, validator?: string[] | ((libraryUrl: string) => boolean)) => true;
export declare const parseLibraryTokensFromUrl: () => {
    libraryUrl: string;
    idToken: string | null;
} | null;
export declare const getLibraryItemsHash: (items: LibraryItems) => number;
export declare const useHandleLibrary: (opts: {
    excalidrawAPI: ExcalidrawImperativeAPI | null;
    /**
     * Return `true` if the library install url should be allowed.
     * If not supplied, only the excalidraw.com base domain is allowed.
     */
    validateLibraryUrl?: ((libraryUrl: string) => boolean) | undefined;
} & ({
    /** @deprecated we recommend using `opts.adapter` instead */
    getInitialLibraryItems?: (() => MaybePromise<LibraryItemsSource>) | undefined;
} | {
    adapter: LibraryPersistenceAdapter;
    /**
     * Adapter that takes care of loading data from legacy data store.
     * Supply this if you want to migrate data on initial load from legacy
     * data store.
     *
     * Can be a different LibraryPersistenceAdapter.
     */
    migrationAdapter?: LibraryMigrationAdapter | undefined;
})) => void;
