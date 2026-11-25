// What notd's Excalidraw extension needs, in one module: React and Excalidraw.
export { default as React } from 'react';
export { createRoot } from 'react-dom/client';
export { Excalidraw, exportToBlob, serializeAsJSON, restore } from '@excalidraw/excalidraw';
