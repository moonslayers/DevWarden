---
paths:
  - 'app/Ai/Tools/**'
---

# Tools

## DuckDuckGo returns HTTP 202 anomaly pages and blocks datacenter IPs
DuckDuckGo html/lite endpoints respond 200 for real results but 202 for an "anomaly" anti-bot challenge page (canonical -> duckduckgo.com, no .result__a nodes). Treat 200 as the only success; any other status means the search failed. DDG also rate-limits/blocks rapidly and from datacenter IPs (verified even via curl), so live verification can intermittently fail while the code is correct. DuckDuckGoSearchTool falls back to lite.duckduckgo.com and returns a readable error instead of throwing.

## Image tools: search → download → marker; SSRF hardening
DuckDuckGoImageSearchTool uses DDG image search (vqd token from search page then i.js JSON; the 202/rate-limit gotcha applies, graceful readable error). DownloadImageTool downloads a public image via the shared ValidatesPublicUrl trait, stores under telegram-media/ on the local disk, and returns a marker [IMAGE:<relative-path>]. isPublicUrl() alone is NOT sufficient SSRF protection: never auto-follow redirects (re-validate each hop manually, max 3) and reject alternative IPv4 encodings (2130706433, 0x7f000001, 0177.0.0.1, 127.1) that cURL resolves to private/loopback. Enforce the size limit via Content-Length + bounded streaming, not full body buffer.
