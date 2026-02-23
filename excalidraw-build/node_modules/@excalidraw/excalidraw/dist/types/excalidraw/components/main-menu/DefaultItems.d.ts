import type { Theme } from "../../element/types";
import "./DefaultItems.scss";
export declare const LoadScene: {
    (): import("react/jsx-runtime").JSX.Element | null;
    displayName: string;
};
export declare const SaveToActiveFile: {
    (): import("react/jsx-runtime").JSX.Element | null;
    displayName: string;
};
export declare const SaveAsImage: {
    (): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export declare const CommandPalette: {
    (opts?: {
        className?: string;
    }): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export declare const SearchMenu: {
    (opts?: {
        className?: string;
    }): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export declare const Help: {
    (): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export declare const ClearCanvas: {
    (): import("react/jsx-runtime").JSX.Element | null;
    displayName: string;
};
export declare const ToggleTheme: {
    (props: {
        allowSystemTheme: true;
        theme: Theme | "system";
        onSelect: (theme: Theme | "system") => void;
    } | {
        allowSystemTheme?: false | undefined;
        onSelect?: ((theme: Theme) => void) | undefined;
    }): import("react/jsx-runtime").JSX.Element | null;
    displayName: string;
};
export declare const ChangeCanvasBackground: {
    (): import("react/jsx-runtime").JSX.Element | null;
    displayName: string;
};
export declare const Export: {
    (): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export declare const Socials: {
    (): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export declare const LiveCollaborationTrigger: {
    ({ onSelect, isCollaborating, }: {
        onSelect: () => void;
        isCollaborating: boolean;
    }): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
