---
paths:
  - 'resources/js/pages/Skills.vue,tests/Feature/**'
---

# Feature

## Skills page (top-level) stats prop contract
SkillController (App\Http\Controllers, top-level after the settings move) renders 'Skills' with props `skills` + `stats`. `stats` fixed shape: { total_matches, active_count, inactive_count, matches_by_day: { labels: string[], count: number[] } (14 days zero-filled), top_skills: [{ id, name, match_count }] (top 5 desc) }. Frontend: route helper `index` from @/routes/skills, controller import @/actions/App/Http/Controllers/SkillController, chart cards with data-test skills-usage-chart-card (line) and skills-top-chart-card (bar).
