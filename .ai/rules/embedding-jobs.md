---
paths:
  - 'app/Services/Embedding/**,app/Jobs/**'
---

# Embedding Jobs

## Transformers package requires ext-ffi at runtime; php.ini has it disabled
codewithkyrian/transformers (0.6.2) needs ext-ffi: composer require must run with `--ignore-platform-req=ext-ffi` because /etc/php/php.ini has `;extension=ffi` commented out (the module exists at /usr/lib/php/modules/ffi.so). CLI/web runs must load FFI explicitly (`php -d extension=ffi`) until it is enabled in php.ini. LocalEmbeddingService checks extension_loaded('ffi') and throws EmbeddingException with a clear message instead of crashing. Model cache lives in storage/app/embedding-models (~133MB, gitignored).
