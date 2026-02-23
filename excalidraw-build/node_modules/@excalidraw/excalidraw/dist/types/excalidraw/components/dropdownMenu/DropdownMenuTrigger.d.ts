/// <reference types="react" />
declare const MenuTrigger: {
    ({ className, children, onToggle, title, ...rest }: {
        className?: string | undefined;
        children: React.ReactNode;
        onToggle: () => void;
        title?: string | undefined;
    } & Omit<import("react").ButtonHTMLAttributes<HTMLButtonElement>, "onSelect">): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export default MenuTrigger;
