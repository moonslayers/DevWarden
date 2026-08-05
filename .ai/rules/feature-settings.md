---
paths:
  - 'app/Http/Controllers/Settings/**,resources/js/pages/settings/**,tests/Feature/Settings/**'
---

# Feature Settings

## New settings pages need a built Vite manifest before assertInertia tests pass
assertInertia tests render the full HTML page, and app.blade.php loads "resources/js/pages/{component}.vue" via @vite, which throws ViteException (500) if the page is absent from public/build/manifest.json. When a Task creates a new settings page + controller/tests, the page .vue (even a placeholder stub) must exist AND `npm run build` must run to regenerate the gitignored manifest before the controller tests pass. Task 6 frontend work replaces the stub.
