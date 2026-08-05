---
paths:
  - 'app/Ai/**'
---

# App Ai

## Bot agent tools pattern (search → links → fetch)
The Telegram bot's AI tools live in app/Ai/Tools/ and implement Laravel\Ai\Contracts\Tool (description()/handle(Request)/schema(JsonSchema)). BotAgent implements HasTools and returns them from tools(); the laravel/ai prompt flow injects them automatically (openai-compatible providers such as opencode-go support tools). Contract: DuckDuckGoSearchTool returns results with real decoded URLs (title/url/snippet), the model picks one, and FetchWebPageTool reads it — read-only, no JS, SSRF-guarded. FetchWebPageTool truncates to ~6000 chars. New bot tools must be added to BotAgent::tools() and keep the search→fetch contract.
