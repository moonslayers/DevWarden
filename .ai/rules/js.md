---
paths:
  - 'resources/js/**'
---

# Js

## reka-ui Switch is modelValue-driven — bind with v-model, never :checked
The project's ui/switch/Switch.vue is a pass-through over reka-ui SwitchRoot, which is controlled via modelValue and emits ONLY update:modelValue (it has NO checked prop). Using :checked + @update:checked silently breaks state (the event never fires), so subagents/Index.vue shipped a switch that looked like it toggled but always submitted is_active='0'. Always use v-model (trueValue defaults to true) and feed a hidden '1'/'0' input, since Inertia's <Form> serializes named inputs only.
