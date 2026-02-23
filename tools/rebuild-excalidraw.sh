#!/bin/bash
# Rebuild the Excalidraw IIFE bundle (Poznote-style local build).
# Run from project root: ./tools/rebuild-excalidraw.sh

set -e
echo "Rebuilding Excalidraw bundle..."
cd excalidraw-build
npm install
npm run build
echo "Build completed. Bundle: public/js/excalidraw-dist/excalidraw-bundle.iife.js"
cd ..
