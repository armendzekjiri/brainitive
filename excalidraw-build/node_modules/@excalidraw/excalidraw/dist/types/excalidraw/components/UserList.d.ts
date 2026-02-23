import "./UserList.scss";
import React from "react";
import type { Collaborator, SocketId } from "../types";
export type GoToCollaboratorComponentProps = {
    socketId: SocketId;
    collaborator: Collaborator;
    withName: boolean;
    isBeingFollowed: boolean;
};
type UserListUserObject = Pick<Collaborator, "avatarUrl" | "id" | "socketId" | "username" | "isInCall" | "isSpeaking" | "isMuted">;
type UserListProps = {
    className?: string;
    mobile?: boolean;
    collaborators: Map<SocketId, UserListUserObject>;
    userToFollow: SocketId | null;
};
export declare const UserList: React.MemoExoticComponent<({ className, mobile, collaborators, userToFollow }: UserListProps) => import("react/jsx-runtime").JSX.Element>;
export {};
