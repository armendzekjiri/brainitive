import { defineConfig } from 'wxt';
import { CLOUD_ORIGIN } from './lib/config';

export default defineConfig({
    modules: ['@wxt-dev/module-react'],
    manifest: ({ browser }) => ({
        name: 'Brainitive',
        description: 'Save pages, links, issues, docs and screenshots to Brainitive.',
        homepage_url: 'https://brainitive.com',
        permissions: [
            'activeTab',
            'contextMenus',
            'identity',
            'scripting',
            'storage',
            ...(browser === 'firefox' ? [] : ['sidePanel']),
        ],
        // BRAINITIVE_DEV_ORIGIN=http://127.0.0.1:8000 npm run dev — pre-grants a local server.
        host_permissions: [`${CLOUD_ORIGIN}/*`, ...(process.env.BRAINITIVE_DEV_ORIGIN ? [`${process.env.BRAINITIVE_DEV_ORIGIN}/*`] : [])],
        optional_host_permissions: ['https://*/*', 'http://*/*'],
        action: { default_title: 'Save to Brainitive' },
        omnibox: { keyword: 'bt' },
        commands: {
            _execute_action: {
                suggested_key: { default: 'Alt+Shift+B' },
                description: 'Open the Brainitive popup',
            },
            'screenshot-area': {
                suggested_key: { default: 'Alt+Shift+S' },
                description: 'Screenshot an area into a new issue',
            },
        },
        ...(browser === 'firefox'
            ? {
                  browser_specific_settings: {
                      gecko: {
                          id: 'extension@brainitive.app',
                          strict_min_version: '128.0',
                          // What you capture goes to your Brainitive server (AMO data disclosure).
                          data_collection_permissions: { required: ['websiteContent'] },
                      },
                  },
              }
            : {}),
    }),
});
