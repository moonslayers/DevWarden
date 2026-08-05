---
paths:
  - 'app/Ai/Tools/**,app/Http/Controllers/**'
---

# Controllers

## App timezone is the intentional .env-driven config exception
The app timezone is the one deliberate .env-driven setting (APP_TIMEZONE in .env, currently America/Los_Angeles), unlike AI/bot config which comes only from the DB. CurrentDateTool uses now() so it returns local time automatically — do NOT hardcode a zone in the tool and do NOT migrate timezone to a DB setting. Caution: Eloquent timestamps and DashboardController::bucketDaily() are timezone-sensitive (Carbon::parse in app timezone); changing APP_TIMEZONE shifts daily buckets and mixes historical UTC rows with new local-time rows (accepted dev tradeoff).
