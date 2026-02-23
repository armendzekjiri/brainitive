import type { DialogProps } from "./Dialog";
import "./ConfirmDialog.scss";
interface Props extends Omit<DialogProps, "onCloseRequest"> {
    onConfirm: () => void;
    onCancel: () => void;
    confirmText?: string;
    cancelText?: string;
}
declare const ConfirmDialog: (props: Props) => import("react/jsx-runtime").JSX.Element;
export default ConfirmDialog;
