import React from 'react';
import ReactDOM from 'react-dom/client';
import { Excalidraw, exportToCanvas } from '@excalidraw/excalidraw';
import '@excalidraw/excalidraw/index.css';
import _ from 'lodash';

// Excalidraw uses lodash internally
window._ = _;

window.BrainitiveExcalidraw = {
  init: (containerId, options = {}) => {
    const container = document.getElementById(containerId);
    if (!container) {
      throw new Error(`Container with id "${containerId}" not found`);
    }

    const root = ReactDOM.createRoot(container);
    let excalidrawAPI = null;
    let lastElements = [];
    let lastAppState = {};
    let lastFiles = {};

    const initialData = {
      elements: options.initialData?.elements ?? [],
      appState: options.initialData?.appState ?? {},
      files: options.initialData?.files ?? {},
      libraryItems: options.initialData?.libraryItems ?? [],
      ...options.initialData,
    };

    const ExcalidrawWrapper = () => (
      <Excalidraw
        initialData={initialData}
        theme={options.theme || 'light'}
        excalidrawAPI={(api) => {
          excalidrawAPI = api;
        }}
        onChange={(elements, appState, files) => {
          lastElements = elements;
          lastAppState = appState;
          lastFiles = files;
        }}
      />
    );

    root.render(<ExcalidrawWrapper />);

    return {
      getSceneElements: () => (excalidrawAPI ? excalidrawAPI.getSceneElements() : lastElements),
      getAppState: () => (excalidrawAPI ? excalidrawAPI.getAppState() : lastAppState),
      getFiles: () => (excalidrawAPI ? excalidrawAPI.getFiles() : lastFiles),
      getLibraryItems: () => {
        if (excalidrawAPI && typeof excalidrawAPI.getLibraryItems === 'function') {
          return excalidrawAPI.getLibraryItems();
        }
        return [];
      },
      exportToCanvas: (exportOptions) => exportToCanvas(exportOptions),
    };
  },
  exportToCanvas,
};
