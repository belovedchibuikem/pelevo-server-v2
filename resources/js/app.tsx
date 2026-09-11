import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { AdminThemeProvider } from './hooks/useAdminTheme';
import AdminShell from './layouts/admin/AdminShell';
import MarketingLayout from './layouts/public/MarketingLayout';

const authenticatedAdminPages = new Set([
  'Admin/Advanced',
  'Admin/Catalog',
  'Admin/Claims',
  'Admin/Creators',
  'Admin/Dashboard',
  'Admin/Finance',
  'Admin/Moderation',
  'Admin/ModuleWorkspace',
  'Admin/Operations',
  'Admin/UserDetail',
  'Admin/SupportTicket',
  'Admin/CanonicalDetail',
  'Admin/Configuration',
  'Admin/Communications',
  'Admin/ErrorState',
  'Admin/Profile',
  'Admin/Integrations',
]);

createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true }) as Record<string, { default: React.ComponentType }>;
    const page = pages[`./Pages/${name}.tsx`].default as React.ComponentType & { layout?: (page: React.ReactNode) => React.ReactNode };
    if (authenticatedAdminPages.has(name)) page.layout = pageNode => <AdminShell>{pageNode}</AdminShell>;
    if (name.startsWith('Public/')) page.layout = pageNode => <MarketingLayout>{pageNode}</MarketingLayout>;
    return page;
  },
  setup({ el, App, props }) {
    const isAdmin = String(props.initialPage.component).startsWith('Admin/');
    createRoot(el).render(
      isAdmin ? (
        <AdminThemeProvider>
          <App {...props} />
        </AdminThemeProvider>
      ) : (
        <App {...props} />
      ),
    );
  },
});
