---
paths:
  - 'app/Ai/Tools/Opencode/**'
---

# Tools Opencode

## Workflow steps run via fire prompts with the orchestrator agent, not opencode_command_execute
OpencodeSessionManager exposes no command-execution method (see .ai/rules/opencode.md), so the tools send fire/reply prompts that ask the agent to run the step, defaulting agent to 'orchestrator' (the agent that interprets workflow commands); the model can override via the tool's optional agent arg. Step prompts are built by OpencodeWorkflowTool::stepPrompt() and never repeat the requirement on advance (the opencode session keeps context).

## Advance must flip the workflow back to Running so the monitor picks it up
OpencodeAdvanceWorkflowTool dispatches the next step (step row Running) but previously left the workflow status as WaitingConfirmation. opencode:monitor only polls workflows with status == running, so the dispatched step was orphaned. The tool must set status back to Running in the same update that bumps current_step. Caught by the E2E lifecycle test (tests/Feature/Opencode/OpencodeWorkflowLifecycleTest.php).

## Chat context for workflow tools comes from OpencodeWorkflowContext (static), set before the agent runs
The model cannot know the Telegram chat_id/user_id, so App\Ai\Tools\Opencode\OpencodeWorkflowContext is a static holder that must be populated with set($chatId, $ownerId) before BotAgent::respond() calls prompt() and cleared in a finally after (queue workers must not leak one chat's context into another). The tools also accept optional chat_id/user_id schema args that override the context; Start errors when neither is available, while Advance/Status/Stop/Ask fall back to the most recent active workflow (Running/WaitingConfirmation) when no chat is known.

## Start stops a previous active workflow; Advance replies before advancing
OpencodeStartWorkflowTool now stops any Running/WaitingConfirmation workflow for the same chat before creating a new one (aborts its opencode session via try/catch, marks workflow + running step Stopped, notifies the owner via OpencodeNotifier, and mentions the stopped workflow in the return). OpencodeAdvanceWorkflowTool accepts an optional reply_to_session arg that is sent to the session via manager->reply() BEFORE advancing (failure returns a readable error without advancing) and an optional agent arg that overrides the orchestrator default in advanceSession opts. OpencodeAskTool's description also covers answering the session's pending questions (reply() path).
