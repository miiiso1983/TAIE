import React from 'react';
export function BrandMark({className=''}) {
 return <svg className={`taie-mark ${className}`} viewBox="0 0 156 144" aria-hidden="true" focusable="false"><g fill="currentColor"><path d="M0 0h40v40H0zM52 0h40v40H52zM104 0h40v40h-40z"/></g><g fill="#54BBFC"><path d="M0 52h40v40H0zM52 52h40v92H52z"/></g><path fill="#45C98D" d="M124 48l8 20 20 8-20 8-8 20-8-20-20-8 20-8z"/></svg>;
}
export function Brand({small=false}) {
 return <div className={`brand taie-brand ${small?'small':''}`} aria-label="TAIE — Teami AI Enterprise" dir="ltr"><BrandMark/><div className="taie-wordmark"><strong>TAIE</strong><span>Teami AI Enterprise</span></div></div>;
}
export function BrandBlocks({className=''}) {
 return <div className={`brand-blocks ${className}`} aria-hidden="true"><i/><i/><i/><i/><i/><i/></div>;
}
