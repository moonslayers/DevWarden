---
paths:
  - 'app/Jobs/**,app/Services/Telegram/**'
---

# Jobs Services Telegram

## Storage::makeDirectory takes only $path (no force: named arg)
Laravel 13 FilesystemAdapter::makeDirectory($path) accepts a single positional arg (the underlying Flysystem createDirectory is already recursive). Calling it with named params like force: true throws "Unknown named parameter $force". Use `$disk->makeDirectory('telegram-media/incoming');`.
