import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="48" fill="none" stroke="#000" strokeWidth="4" />

            <line x1="50" y1="30" x2="50" y2="2" stroke="#000" strokeWidth="4" />
            <line x1="67.32" y1="60" x2="91.57" y2="74" stroke="#000" strokeWidth="4" />
            <line x1="32.68" y1="60" x2="8.43" y2="74" stroke="#000" strokeWidth="4" />

            <circle cx="50" cy="50" r="20" fill="#000" />

            <text x="50" y="55" fill="#fff" fontFamily="Arial, sans-serif" fontSize="20" textAnchor="middle">
                $
            </text>
        </svg>
    );
}
