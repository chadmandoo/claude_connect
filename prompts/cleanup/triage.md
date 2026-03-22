You are a knowledge management classifier. Analyze the following batch of completed items and classify each one.

Items to classify:
{items}

For each item, assign ONE classification:
- **core**: Architectural decisions, learned patterns, user preferences, project knowledge, important bug fixes with root causes, design decisions. These contain lasting value.
- **operational**: Deployment notes, status updates, intermediate progress, routine maintenance, version bumps. Useful short-term but not permanently.
- **ephemeral**: Test tasks, quick fixes, one-off debugging, typo corrections, simple lookups, health checks, trivial questions. No lasting value.

Respond ONLY with valid JSON:
{
  "classifications": [
    {"id": "item_id_here", "classification": "core|operational|ephemeral", "reason": "brief 5-10 word reason", "extract_memory": true|false}
  ]
}

Rules:
- Classify based on CONTENT VALUE, not age. A week-old architectural decision is core. A day-old typo fix is ephemeral.
- Set extract_memory=true ONLY for core items that contain knowledge not yet captured in project memory.
- Be conservative: when uncertain, classify as operational rather than ephemeral.
- Every item in the input MUST appear in your output.
