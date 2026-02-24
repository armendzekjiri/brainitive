import { ImgHTMLAttributes } from 'react';

interface ApplicationLogoProps extends Omit<ImgHTMLAttributes<HTMLImageElement>, 'src'> {}

export default function ApplicationLogo({ className = '', alt = 'Logo', ...props }: ApplicationLogoProps) {
    return (
        <img
            src="/images/logo.png"
            alt={alt}
            className={className}
            {...props}
        />
    );
}
