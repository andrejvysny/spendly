import React from 'react';

function ValueSplit({ data, className }: { className?:string|null, data: { label: string, value: string|number|boolean|null }[] }) {
    return (
        <div className={className + " space-y-2"}>
            {data.map((data, index) => (
                <div key={index} className="flex justify-between text-sm">
                    <span className="text-muted-foreground">{data.label}</span>
                    <span className="text-base">{data.value}</span>
                </div>
            ))}
        </div>
            );
            }

            export default ValueSplit;
