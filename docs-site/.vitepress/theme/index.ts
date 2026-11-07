import DefaultTheme from 'vitepress/theme';
import type { Theme } from 'vitepress';
import ApiReference from './ApiReference.vue';
import './custom.css';

export default {
    extends: DefaultTheme,
    enhanceApp({ app }) {
        // docs.brainitive.app/api: the OpenAPI reference (Developer API)
        app.component('ApiReference', ApiReference);
    },
} satisfies Theme;
