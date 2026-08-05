#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

# Run the whole app + Telegram bot locally in one terminal:
#   php artisan serve          -> web UI (http://localhost:8012)
#   php artisan schedule:work  -> runs the long-polling schedule (telegram:poll every minute)
#   php artisan queue:work     -> processes the bot jobs (message buffer -> ProcessTelegramPendingBatch -> SendTelegramReply)
#
# The queue worker runs with FFI enabled (-d extension=ffi) so local ONNX
# embeddings (codewithkyrian/transformers) work for bot memory capture.
# The web server and scheduler do not run embeddings, so they don't need FFI.
#
# Requires a database-backed queue: QUEUE_CONNECTION=database (default in .env).

cleanup() {
    trap - INT TERM EXIT
    echo ""
    echo "Stopping dev-full processes..."
    kill 0 2>/dev/null || true
    wait 2>/dev/null || true
}
trap cleanup INT TERM EXIT

echo "Starting DevWarden (web + scheduler + queue). Ctrl+C to stop all."
"php" artisan serve --port=8012 &
"php" artisan schedule:work &
"php" -d extension=ffi artisan queue:work &

wait
