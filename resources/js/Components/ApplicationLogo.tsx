import { ImgHTMLAttributes } from 'react';

interface ApplicationLogoProps extends Omit<ImgHTMLAttributes<HTMLImageElement>, 'src'> {
    /** Set to true for the LCP logo (e.g. nav logo on Welcome) to get fetchpriority="high" */
    priority?: boolean;
}

export default function ApplicationLogo({
    className = '',
    alt = 'Logo',
    width = 140,
    height = 36,
    priority = false,
    ...props
}: ApplicationLogoProps) {
    return (
        <img
            src="/images/logo.png"
            alt={alt}
            width={width}
            height={height}
            fetchPriority={priority ? 'high' : undefined}
            className={className}
            {...props}
        />
    );
}
