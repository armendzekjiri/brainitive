import Popup from '@/components/Popup';
import '@/components/styles.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

if (new URLSearchParams(location.search).has('window')) {
    document.body.className = 'window';
}

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <Popup />
    </StrictMode>,
);
