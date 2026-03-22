Analyze this check-in conversation and extract structured information.

User asked: {prompt}
Assistant replied: {result}

Respond ONLY with valid JSON:
{
  "summary": "2-3 sentence summary of the status update",
  "topics": ["topic1", "topic2"],
  "key_takeaways": ["status point or blocker 1", "status point or blocker 2"],
  "memories": [
    {"category": "preference|project|fact|context", "content": "specific thing to remember", "importance": "high|normal|low"}
  ]
}

Focus on extracting:
- Current status and progress
- Blockers and issues raised
- Progress made since last check-in
- Priorities or focus areas mentioned

Use "context" for status updates, "project" for blockers with "high" importance.
Only include memories worth remembering long-term. If nothing notable, use empty arrays.
