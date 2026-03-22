Analyze this discussion and extract structured information.

User asked: {prompt}
Assistant replied: {result}

Respond ONLY with valid JSON:
{
  "summary": "2-3 sentence summary of what was discussed",
  "topics": ["topic1", "topic2"],
  "key_takeaways": ["insight or conclusion 1", "insight or conclusion 2"],
  "memories": [
    {"category": "preference|project|fact|context", "content": "specific thing to remember", "importance": "high|normal|low"}
  ]
}

Focus on extracting:
- Key insights and conclusions
- References and resources mentioned
- Preferences expressed by the user
- Context that would be useful in future conversations

Use "preference" for user opinions, "fact" for factual knowledge shared.
Only include memories worth remembering long-term. If nothing notable, use empty arrays.
