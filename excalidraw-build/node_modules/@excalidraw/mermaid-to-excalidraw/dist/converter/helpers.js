import { CONTAINER_STYLE_PROPERTY, LABEL_STYLE_PROPERTY, } from "../interfaces.js";
import { removeMarkdown } from "@excalidraw/markdown-to-text";
/**
 * Convert mermaid edge type to Excalidraw arrow type
 */
const MERMAID_EDGE_TYPE_MAPPER = {
    arrow_circle: {
        endArrowhead: "dot",
    },
    arrow_cross: {
        endArrowhead: "bar",
    },
    arrow_open: {
        endArrowhead: null,
        startArrowhead: null,
    },
    double_arrow_circle: {
        endArrowhead: "dot",
        startArrowhead: "dot",
    },
    double_arrow_cross: {
        endArrowhead: "bar",
        startArrowhead: "bar",
    },
    double_arrow_point: {
        endArrowhead: "arrow",
        startArrowhead: "arrow",
    },
};
export const computeExcalidrawArrowType = (mermaidArrowType) => {
    return MERMAID_EDGE_TYPE_MAPPER[mermaidArrowType];
};
// Get text from graph elements, fallback markdown to text
export const getText = (element) => {
    let text = element.text;
    if (element.labelType === "markdown") {
        text = removeMarkdown(element.text);
    }
    return removeFontAwesomeIcons(text);
};
/**
 * Remove font awesome icons support from text
 */
const removeFontAwesomeIcons = (input) => {
    const fontAwesomeRegex = /\s?(fa|fab):[a-zA-Z0-9-]+/g;
    return input.replace(fontAwesomeRegex, "");
};
/**
 * Compute style for vertex
 */
export const computeExcalidrawVertexStyle = (style) => {
    const excalidrawProperty = {};
    Object.keys(style).forEach((property) => {
        switch (property) {
            case CONTAINER_STYLE_PROPERTY.FILL: {
                excalidrawProperty.backgroundColor = style[property];
                excalidrawProperty.fillStyle = "solid";
                break;
            }
            case CONTAINER_STYLE_PROPERTY.STROKE: {
                excalidrawProperty.strokeColor = style[property];
                break;
            }
            case CONTAINER_STYLE_PROPERTY.STROKE_WIDTH: {
                excalidrawProperty.strokeWidth = Number(style[property]?.split("px")[0]);
                break;
            }
            case CONTAINER_STYLE_PROPERTY.STROKE_DASHARRAY: {
                excalidrawProperty.strokeStyle = "dashed";
                break;
            }
        }
    });
    return excalidrawProperty;
};
/**
 * Compute style for label
 */
export const computeExcalidrawVertexLabelStyle = (style) => {
    const excalidrawProperty = {};
    Object.keys(style).forEach((property) => {
        switch (property) {
            case LABEL_STYLE_PROPERTY.COLOR: {
                excalidrawProperty.strokeColor = style[property];
                break;
            }
        }
    });
    return excalidrawProperty;
};
