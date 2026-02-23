import { DEFAULT_FONT_SIZE } from "./constants.js";
import { graphToExcalidraw } from "./graphToExcalidraw.js";
import { parseMermaid } from "./parseMermaid.js";
const parseMermaidToExcalidraw = async (definition, config) => {
    const mermaidConfig = config || {};
    const fontSize = parseInt(mermaidConfig.themeVariables?.fontSize ?? "") || DEFAULT_FONT_SIZE;
    const parsedMermaidData = await parseMermaid(definition, {
        ...mermaidConfig,
        themeVariables: {
            ...mermaidConfig.themeVariables,
            // Multiplying by 1.25 to increase the font size by 25% and render correctly in Excalidraw
            fontSize: `${fontSize * 1.25}px`,
        },
    });
    // Only font size supported for excalidraw elements
    const excalidrawElements = graphToExcalidraw(parsedMermaidData, {
        fontSize,
    });
    return excalidrawElements;
};
export { parseMermaidToExcalidraw };
