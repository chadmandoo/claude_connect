Analyze this planning conversation and extract structured information.

User asked: {prompt}
Assistant replied: {result}

Respond ONLY with valid JSON:
{
  "summary": "2-3 sentence summary of what was planned",
  "topics": ["topic1", "topic2"],
  "key_takeaways": ["decision or action item 1", "decision or action item 2"],
  "memories": [
    {"category": "preference|project|fact|context", "content": "specific thing to remember", "importance": "high|normal|low"}
  ],
  "work_items": [
    {"title": "short actionable title", "description": "what needs to be done", "priority": "normal|high|low|urgent"}
  ]
}

Focus on extracting:
- Decisions that were made (use "project" category, "high" importance)
- Rationale behind decisions
- Next steps and action items
- Open questions that remain
- Architecture or design choices

Decisions should be stored as "project" category with "high" importance.
Only include memories worth remembering long-term. If nothing notable, use empty arrays.

For work_items: extract concrete next steps and action items that were decided on. Each item should be a discrete, actionable task. Only include items that represent real work to be done, not vague ideas. If nothing actionable, use empty array.
