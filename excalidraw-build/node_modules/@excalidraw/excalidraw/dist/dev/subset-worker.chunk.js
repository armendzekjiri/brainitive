import {
  Commands,
  subsetToBinary
} from "./chunk-OKSO7T74.js";
import "./chunk-66VA7UC4.js";
import "./chunk-XDFCUUT6.js";

// subset/subset-worker.chunk.ts
var WorkerUrl = import.meta.url ? new URL(import.meta.url) : void 0;
if (typeof window === "undefined" && typeof self !== "undefined") {
  self.onmessage = async (e) => {
    switch (e.data.command) {
      case Commands.Subset:
        const buffer = await subsetToBinary(
          e.data.arrayBuffer,
          e.data.codePoints
        );
        self.postMessage(buffer, { transfer: [buffer] });
        break;
    }
  };
}
export {
  WorkerUrl
};
//# sourceMappingURL=subset-worker.chunk.js.map
