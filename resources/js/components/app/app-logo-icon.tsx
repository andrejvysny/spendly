import { SVGAttributes, useId } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const maskId = useId();

    return (
        <svg {...props} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" aria-hidden="true">
            <defs>
                <mask id={maskId}>
                    <circle cx="50" cy="50" r="46" fill="white" />
                    {/* Upper S lobe: larger oval, shallower angle, positioned upper-right */}
                    <ellipse cx="58" cy="30" rx="26" ry="10" transform="rotate(-27 58 30)" fill="black" />
                    {/* Lower S lobe: mirror of upper, positioned lower-left */}
                    <ellipse cx="42" cy="70" rx="26" ry="10" transform="rotate(-27 42 70)" fill="black" />
                </mask>
            </defs>
            <circle cx="50" cy="50" r="46" mask={`url(#${maskId})`} fill="currentColor" />
        </svg>
    );
}
