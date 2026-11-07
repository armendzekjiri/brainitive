<script setup lang="ts">
/**
 * docs.brainitive.app/api: the API reference rendered from the committed OpenAPI file (public/openapi.json —
 * a copy of docs/api/openapi.json that `composer openapi` writes and OpenApiSpecTest keeps in sync), with Scalar
 * (pinned, from jsDelivr). "Try it" is hidden: the API sends no CORS headers, so a browser can't call it, and a
 * personal API key doesn't belong in a web page.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { withBase } from 'vitepress';

const SCALAR = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.75.1';
const host = ref<HTMLElement | null>(null);
const failed = ref(false);
let app: { destroy?: () => void } | null = null;

function load(): Promise<void> {
    const w = window as unknown as { Scalar?: unknown };
    if (w.Scalar) return Promise.resolve();
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCALAR;
        script.async = true;
        script.crossOrigin = 'anonymous';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Scalar failed to load'));
        document.head.appendChild(script);
    });
}

onMounted(async () => {
    try {
        await load();
        const scalar = (window as unknown as { Scalar: { createApiReference: (el: HTMLElement, config: Record<string, unknown>) => { destroy?: () => void } } }).Scalar;
        if (!host.value) return;
        app = scalar.createApiReference(host.value, {
            url: withBase('/openapi.json'),
            hideClientButton: true,
            hideTestRequestButton: true,
            hideDarkModeToggle: true,
            withDefaultFonts: false,
            telemetry: false,
            agent: { disabled: true },
            showDeveloperTools: 'never',
            defaultOpenAllTags: false,
            metaData: { title: 'Brainitive API reference' },
        });
    } catch {
        failed.value = true;
    }
});

onBeforeUnmount(() => app?.destroy?.());
</script>

<template>
    <div class="api-reference">
        <p v-if="failed" class="api-reference__fallback">
            The interactive reference couldn't load. Download the OpenAPI file instead:
            <a :href="withBase('/openapi.json')">openapi.json</a>.
        </p>
        <div ref="host" />
        <noscript>
            <p>The reference needs JavaScript. The OpenAPI file: <a href="/openapi.json">openapi.json</a>.</p>
        </noscript>
    </div>
</template>

<style scoped>
.api-reference {
    min-height: 60vh;
}
.api-reference__fallback {
    padding: 24px;
}
</style>
