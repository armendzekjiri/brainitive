/// <reference types="react" />
import "./WelcomeScreen.scss";
declare const WelcomeScreen: {
    (props: {
        children?: React.ReactNode;
    }): import("react/jsx-runtime").JSX.Element;
    displayName: string;
    Center: {
        ({ children }: {
            children?: import("react").ReactNode;
        }): import("react/jsx-runtime").JSX.Element;
        displayName: string;
        Logo: {
            ({ children }: {
                children?: import("react").ReactNode;
            }): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        Heading: {
            ({ children }: {
                children: import("react").ReactNode;
            }): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        Menu: {
            ({ children }: {
                children?: import("react").ReactNode;
            }): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        MenuItem: {
            ({ onSelect, children, icon, shortcut, className, ...props }: {
                onSelect: () => void;
                children: import("react").ReactNode;
                icon?: import("react").JSX.Element | undefined;
                shortcut?: string | null | undefined;
            } & import("react").ButtonHTMLAttributes<HTMLButtonElement>): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        MenuItemLink: {
            ({ children, href, icon, shortcut, className, ...props }: {
                children: import("react").ReactNode;
                href: string;
                icon?: import("react").JSX.Element | undefined;
                shortcut?: string | null | undefined;
            } & import("react").AnchorHTMLAttributes<HTMLAnchorElement>): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        MenuItemHelp: {
            (): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        MenuItemLoadScene: {
            (): import("react/jsx-runtime").JSX.Element | null;
            displayName: string;
        };
        MenuItemLiveCollaborationTrigger: {
            ({ onSelect, }: {
                onSelect: () => any;
            }): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
    };
    Hints: {
        MenuHint: {
            ({ children }: {
                children?: import("react").ReactNode;
            }): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        ToolbarHint: {
            ({ children }: {
                children?: import("react").ReactNode;
            }): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
        HelpHint: {
            ({ children }: {
                children?: import("react").ReactNode;
            }): import("react/jsx-runtime").JSX.Element;
            displayName: string;
        };
    };
};
export default WelcomeScreen;
