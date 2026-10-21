import React from 'react';
import ReactDOM from 'react-dom/client';
import {
  Excalidraw,
  exportToCanvas,
  exportToSvg,
  convertToExcalidrawElements,
  getCommonBounds,
  CaptureUpdateAction,
} from '@excalidraw/excalidraw';
import '@excalidraw/excalidraw/index.css';
import _ from 'lodash';
import { TEMPLATES } from './templates';
import { issueCard, isIssueCard } from './issueCards';

// Excalidraw uses lodash internally
window._ = _;

const GAP = 200;

/** Text of an element: its own text, or the text bound into a container (sticky, shape). */
function elementText(element, byId) {
  if (element.type === 'text') {
    return element.originalText ?? element.text;
  }
  const bound = (element.boundElements || []).find((b) => b.type === 'text');
  const text = bound ? byId.get(bound.id) : null;
  return text ? (text.originalText ?? text.text) : '';
}

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
        viewModeEnabled={options.viewModeEnabled ?? false}
        zenModeEnabled={options.zenModeEnabled ?? false}
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

    const allElements = () => (excalidrawAPI ? excalidrawAPI.getSceneElementsIncludingDeleted() : []);
    const liveElements = () => (excalidrawAPI ? excalidrawAPI.getSceneElements() : lastElements);

    /** Add elements as one undoable step. */
    const addElements = (newElements, { select = true } = {}) => {
      excalidrawAPI.updateScene({
        elements: [...allElements(), ...newElements],
        appState: select ? { selectedElementIds: Object.fromEntries(newElements.map((e) => [e.id, true])) } : {},
        captureUpdate: CaptureUpdateAction.IMMEDIATELY,
      });
    };

    /** Scene point in the middle of what the user is looking at. */
    const viewportCenter = () => {
      const { scrollX, scrollY, zoom, width, height } = excalidrawAPI.getAppState();
      return { x: -scrollX + width / 2 / zoom.value, y: -scrollY + height / 2 / zoom.value };
    };

    /**
     * Swap elements for issue cards in place. Ids are kept so arrows stay attached; the
     * old card's bound text is dropped and the new one's added.
     */
    const replaceWithCards = (replacements) => {
      const byId = new Map(allElements().map((e) => [e.id, e]));
      const dropped = new Set();
      const added = [];
      replacements.forEach(({ element, item }) => {
        const arrows = (element.boundElements || []).filter((b) => b.type === 'arrow');
        (element.boundElements || []).filter((b) => b.type === 'text').forEach((b) => dropped.add(b.id));
        dropped.add(element.id);
        const card = convertToExcalidrawElements([issueCard(element.x, element.y, item, element.id)], { regenerateIds: false });
        card.forEach((e) => {
          if (e.id === element.id) {
            e.boundElements = [...(e.boundElements || []), ...arrows];
            e.groupIds = element.groupIds;
            e.frameId = element.frameId;
          }
          added.push(e);
        });
      });
      const kept = [...byId.values()].filter((e) => !dropped.has(e.id));
      excalidrawAPI.updateScene({ elements: [...kept, ...added], captureUpdate: CaptureUpdateAction.IMMEDIATELY });
    };

    return {
      getSceneElements: () => liveElements(),
      getAppState: () => (excalidrawAPI ? excalidrawAPI.getAppState() : lastAppState),
      getFiles: () => (excalidrawAPI ? excalidrawAPI.getFiles() : lastFiles),
      getLibraryItems: () => {
        if (excalidrawAPI && typeof excalidrawAPI.getLibraryItems === 'function') {
          return excalidrawAPI.getLibraryItems();
        }
        return [];
      },
      exportToCanvas: (exportOptions) => exportToCanvas(exportOptions),

      /** Replace the whole scene (e.g. "load latest" after a save conflict). */
      loadScene: (data) => {
        excalidrawAPI.updateScene({ elements: data.elements || [], captureUpdate: CaptureUpdateAction.NEVER });
        if (data.files) {
          excalidrawAPI.addFiles(Object.values(data.files));
        }
      },

      /** Insert a template next to the existing content (or at the origin on an empty board). */
      insertTemplate: (key) => {
        const template = TEMPLATES.find((t) => t.key === key);
        if (!template || !excalidrawAPI) {
          return false;
        }
        const elements = convertToExcalidrawElements(template.build());
        const existing = liveElements();
        if (existing.length > 0) {
          const [, minY, maxX] = getCommonBounds(existing);
          const [tMinX, tMinY] = getCommonBounds(elements);
          const dx = maxX + GAP - tMinX;
          const dy = minY - tMinY;
          elements.forEach((e) => {
            e.x += dx;
            e.y += dy;
          });
        }
        addElements(elements);
        excalidrawAPI.scrollToContent(elements, { fitToViewport: true, viewportZoomFactor: 0.9, animate: true });
        return true;
      },

      /** Texts of the selected stickies / shapes / text elements (one entry per element). */
      getSelectedTexts: () => {
        if (!excalidrawAPI) {
          return [];
        }
        const elements = liveElements();
        const byId = new Map(elements.map((e) => [e.id, e]));
        const selected = excalidrawAPI.getAppState().selectedElementIds || {};
        return elements
          .filter((e) => selected[e.id] && !isIssueCard(e))
          // A selected container's label is reported through the container, not twice
          .filter((e) => !(e.type === 'text' && e.containerId && selected[e.containerId]))
          .map((e) => (e.type === 'text' && e.containerId ? byId.get(e.containerId) : e))
          .filter(Boolean)
          .map((e) => ({ id: e.id, text: elementText(e, byId).trim() }))
          .filter((t) => t.text !== '');
      },

      /** Turn the given elements into live issue cards: [{ elementId, item }]. */
      convertToIssueCards: (pairs) => {
        const byId = new Map(liveElements().map((e) => [e.id, e]));
        replaceWithCards(pairs.map(({ elementId, item }) => ({ element: byId.get(elementId), item })).filter((r) => r.element));
      },

      /** Drop issue cards for these items in the middle of the viewport. */
      insertIssueCards: (items) => {
        if (!excalidrawAPI || items.length === 0) {
          return;
        }
        const center = viewportCenter();
        const perRow = 3;
        const skeletons = items.map((item, i) => issueCard(
          center.x - 450 + (i % perRow) * 310,
          center.y - 45 + Math.floor(i / perRow) * 110,
          item,
        ));
        addElements(convertToExcalidrawElements(skeletons));
      },

      /** Item ids of all issue cards on the board. */
      getIssueCardIds: () => [...new Set(liveElements().filter(isIssueCard).map((e) => e.customData.itemId))],

      /** Redraw cards whose issue changed (status, title, assignee); deleted issues are left as they were. */
      refreshIssueCards: (items) => {
        const byItemId = new Map(items.map((item) => [item.id, item]));
        const replacements = liveElements()
          .filter((e) => isIssueCard(e) && byItemId.has(e.customData.itemId))
          .map((element) => ({ element, item: byItemId.get(element.customData.itemId) }))
          .filter(({ element, item }) => element.customData.signature !== issueCard(0, 0, item).customData.signature);
        if (replacements.length > 0) {
          replaceWithCards(replacements);
        }
        return replacements.length;
      },
    };
  },
  exportToCanvas,

  templates: TEMPLATES.map(({ key, name, description }) => ({ key, name, description })),

  /** SVG thumbnail of a template for the picker. */
  templatePreview: (key) => {
    const template = TEMPLATES.find((t) => t.key === key);
    return exportToSvg({
      elements: convertToExcalidrawElements(template.build()),
      appState: { exportBackground: false },
      files: null,
      exportPadding: 20,
    });
  },
};
