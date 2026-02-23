import { GraphConverter } from "../GraphConverter.js";
import { nanoid } from "nanoid";
export const GraphImageConverter = new GraphConverter({
    converter: (graph) => {
        const imageId = nanoid();
        const { width, height } = graph;
        const imageElement = {
            type: "image",
            x: 0,
            y: 0,
            width,
            height,
            status: "saved",
            fileId: imageId,
        };
        const files = {
            [imageId]: {
                id: imageId,
                mimeType: graph.mimeType,
                dataURL: graph.dataURL,
            },
        };
        return { files, elements: [imageElement] };
    },
});
