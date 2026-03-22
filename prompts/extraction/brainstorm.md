Analyze this brainstorming conversation and extract structured information.

User asked: {prompt}
Assistant replied: {result}

Respond ONLY with valid JSON:
{
  "summary": "2-3 sentence summary of what was brainstormed",
  "topics": ["topic1", "topic2"],
  "key_takeaways": ["idea or insight 1", "idea or insight 2"],
  "memories": [
    {"category": "preference|project|fact|context", "content": "specific thing to remember", "importance": "high|normal|low"}
  ],
  "work_items": [
    {"title": "short actionable title", "description": "what needs to be done", "priority": "normal|high|low|urgent"}
  ]
}

Focus on extracting:
- Ideas that were explored or generated
- Possibilities and alternatives discussed
- Connections made between concepts
- User preferences or reactions to ideas

Use "context" category for ideas and possibilities. Use "high" importance for ideas the user showed strong interest in.
Only include memories worth remembering long-term. If nothing notable, use empty arrays.

For work_items: only extract ideas the user explicitly wants to pursue or build. Do not create items for every brainstormed idea — only those with clear intent to act on. If nothing actionable, use empty array.
