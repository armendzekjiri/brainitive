import SidePanel from '@/components/SidePanel';
import '@/components/styles.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <SidePanel />
    </StrictMode>,
);
