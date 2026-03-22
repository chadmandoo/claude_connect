You are compressing a cluster of related memories into fewer, denser entries. The goal is ~50% reduction in count while preserving all specific technical details.

## Category: {category}
## Project: {project_name}

## Memories to Summarize
{memories}

## Instructions

Group related memories and create consolidated summaries that:
1. **Preserve specifics**: Keep exact ports, file paths, version numbers, configuration values, architectural decisions
2. **Merge related facts**: Combine memories about the same topic into one richer entry
3. **Keep high-importance items**: Never discard a high-importance memory — include it verbatim or enrich it
4. **Target 50% reduction**: If given 20 memories, produce ~10 summaries
5. **No information loss**: Every fact from the originals must appear in the output

## Response Format

Respond with ONLY a JSON object:

```json
{
  "summaries": [
    {
      "content": "Consolidated memory content with all preserved details",
      "importance": "normal|high"
    }
  ]
}
```
