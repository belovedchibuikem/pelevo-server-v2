import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export function useUnsavedChanges(dirty: boolean) {
  useEffect(() => {
    const warn = (event: BeforeUnloadEvent) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } };
    window.addEventListener('beforeunload', warn);
    const remove = router.on('before', event => {
      if (dirty && event.detail.visit.method === 'get' && event.detail.visit.url.pathname !== window.location.pathname && !window.confirm('You have unsaved changes. Leave this page and discard them?')) return false;
    });
    return () => { window.removeEventListener('beforeunload', warn); remove(); };
  }, [dirty]);
}
