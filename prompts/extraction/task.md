Analyze this task conversation and extract structured information.

User asked: {prompt}
Assistant replied: {result}

Respond ONLY with valid JSON:
{
  "summary": "2-3 sentence summary of what was done",
  "topics": ["topic1", "topic2"],
  "key_takeaways": ["outcome or change 1", "outcome or change 2"],
  "memories": [
    {"category": "preference|project|fact|context", "content": "specific thing to remember", "importance": "high|normal|low"}
  ],
  "work_items": [
    {"title": "short actionable title", "description": "what needs to be done", "priority": "normal|high|low|urgent"}
  ]
}

Focus on extracting:
- Changes that were made (files modified, features added, bugs fixed)
- Outcomes and results
- Issues encountered and how they were resolved
- Technical details worth remembering

Use "project" category for technical changes and outcomes. Use "high" importance for significant architectural changes.
Only include memories worth remembering long-term. If nothing notable, use empty arrays.

For work_items: extract follow-up work discovered during implementation — bugs found, remaining tasks, things that need attention next. Only include concrete follow-ups, not the work already done. If nothing remains, use empty array.
