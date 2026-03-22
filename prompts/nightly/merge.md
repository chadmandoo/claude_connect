You are consolidating duplicate memories. Two or more memories have been identified as highly similar. Merge them into a single, comprehensive entry.

## Similar Memories
{cluster}

## Instructions

Create ONE merged memory that:
1. Preserves ALL unique information from both entries
2. Uses the highest importance level from the group
3. Is concise but complete — no information loss
4. Uses clear, specific language (include ports, paths, file names, decisions)
5. Picks the most appropriate category

## Response Format

Respond with ONLY a JSON object:

```json
{
  "content": "The merged memory content",
  "category": "fact|preference|project|context",
  "importance": "normal|high"
}
```
