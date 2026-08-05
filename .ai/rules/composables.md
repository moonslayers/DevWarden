---
paths:
  - 'resources/js/composables/**'
---

# Composables

## CSS theme vars are full hsl() colors — never wrap in hsl()
In resources/css/app.css the theme variables (--chart-1..5, --foreground, --background, --muted-foreground, --border) are declared as COMPLETE colors, e.g. --chart-1: hsl(12 76% 61%). When reading them at runtime via getComputedStyle, use the returned value directly. Do NOT wrap it in hsl() again — that produces hsl(hsl(...)) which is invalid CSS and breaks chart.js colors, legend dots and dark mode. SSR fallback should also be a full color.
