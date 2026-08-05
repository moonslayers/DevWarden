---
paths:
  - 'app/Http/Controllers/SubAgentController.php,app/Models/SubAgentUsageLog.php,app/Models/BotSubAgent.php'
---

# Models Models

## BotSubAgent::usageLogs() relation declares the explicit FK sub_agent_id — aggregates work
sub_agent_usage_logs stores the FK as sub_agent_id, and BotSubAgent::usageLogs() is a hasMany(SubAgentUsageLog::class, 'sub_agent_id') — the explicit FK must stay declared (without it Eloquent would default to bot_sub_agent_id and withCount/withSum would throw SQLSTATE no such column). Aggregates via withCount('usageLogs')/withSum('usageLogs', 'tokens') are fine; covered by a regression test (BotSubAgentTest 'usageLogs relation resolves via the sub_agent_id foreign key'). Note SubAgentUsageLog::$fillable excludes created_at/updated_at, so create([... 'created_at' => ...]) silently drops the timestamp — set it via direct attribute assignment before save().
