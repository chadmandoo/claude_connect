You are reviewing memories for staleness. These memories have not been surfaced in any agent prompt for 30+ days.

## Agent Context
{agent_system_prompt}

## Memories to Review
{memories}

## Instructions
For each memory, decide whether it is still valuable:
- **keep**: The memory contains timeless or periodically relevant information that may be useful in future conversations
- **archive**: The memory is outdated but may have some historical value — leave it but it may be cleaned up later
- **delete**: The memory is no longer useful, accurate, or relevant

Consider:
- Would this memory help the agent do its job if it came up in a future conversation?
- Is the information still accurate or has it likely changed?
- Is this a one-time fact about a completed task, or an ongoing truth?

## Response Format
Return JSON only:
```json
{
  "verdicts": [
    {
      "id": "memory_id",
      "verdict": "keep|archive|delete",
      "confidence": 0.0-1.0,
      "reason": "Brief explanation"
    }
  ]
}
```
