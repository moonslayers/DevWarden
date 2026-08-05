---
paths:
  - 'app/Services/Embedding/**,bin/dev-full.sh'
---

# Embedding

## FFI enabled system-wide; dev-full runs queue worker with -d extension=ffi
PHP FFI is now enabled in /etc/php/php.ini (extension=ffi uncommented, verified: php -m shows FFI, embedding smoke works without -d flag). bin/dev-full.sh launches the queue worker as `php -d extension=ffi artisan queue:work` so local embeddings work in dev. A plain `php artisan queue:work` (cron/systemd) still needs FFI loaded; without it the memory feature degrades silently (bot replies, no memories captured/injected). Model cache: storage/app/embedding-models (~133MB, gitignored). README documents FFI as a requirement.
