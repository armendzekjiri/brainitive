import "./Avatar.scss";
import React from "react";
type AvatarProps = {
    onClick: (e: React.MouseEvent<HTMLDivElement, MouseEvent>) => void;
    color: string;
    name: string;
    src?: string;
    className?: string;
};
export declare const Avatar: ({ color, onClick, name, src, className, }: AvatarProps) => import("react/jsx-runtime").JSX.Element;
export {};
