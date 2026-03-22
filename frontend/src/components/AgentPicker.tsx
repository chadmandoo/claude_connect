import { useState, useEffect, useCallback } from "react";
import { useWs } from "@/hooks/useWebSocket";
import { AgentAvatar } from "./AgentAvatar";
import { Check, ChevronsUpDown } from "lucide-react";
import { cn } from "@/lib/utils";

interface Agent {
  id: string;
  slug: string;
  name: string;
  description: string;
  color: string;
  icon: string;
  is_default: string;
  is_system: string;
}

interface AgentPickerProps {
  value?: string | string[];
  onChange: (value: string | string[]) => void;
  multi?: boolean;
  className?: string;
}

export function AgentPicker({ value, onChange, multi = false, className }: AgentPickerProps) {
  const { request } = useWs();
  const [agents, setAgents] = useState<Agent[]>([]);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    request({ type: "agents.list" }).then((resp) => {
      setAgents((resp.agents as Agent[]) || []);
    }).catch(() => {});
  }, [request]);

  const selectedIds = multi
    ? (Array.isArray(value) ? value : [])
    : (value ? [value as string] : []);

  const selectedAgents = agents.filter((a) => selectedIds.includes(a.id));

  const toggleAgent = (agentId: string) => {
    if (multi) {
      const current = Array.isArray(value) ? value : [];
      if (current.includes(agentId)) {
        onChange(current.filter((id) => id !== agentId));
      } else {
        onChange([...current, agentId]);
      }
    } else {
      onChange(agentId);
      setOpen(false);
    }
  };

  return (
    <div className={cn("relative", className)}>
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className="w-full flex items-center justify-between gap-2 px-3 py-2 rounded-xl border border-border/50 bg-background/50 text-sm hover:border-primary/50 transition-colors"
      >
        <div className="flex items-center gap-2 min-w-0">
          {selectedAgents.length === 0 ? (
            <span className="text-muted-foreground">Select agent...</span>
          ) : multi ? (
            <span className="text-foreground">{selectedAgents.map(a => a.name).join(", ")}</span>
          ) : (
            <>
              <AgentAvatar color={selectedAgents[0]?.color} name={selectedAgents[0]?.name} size="sm" />
              <span className="text-foreground truncate">{selectedAgents[0]?.name}</span>
            </>
          )}
        </div>
        <ChevronsUpDown className="w-4 h-4 text-muted-foreground flex-shrink-0" />
      </button>

      {open && (
        <div className="absolute z-50 mt-1 w-full rounded-xl border border-border/50 bg-card shadow-xl max-h-64 overflow-y-auto">
          {agents.map((agent) => {
            const isSelected = selectedIds.includes(agent.id);
            return (
              <button
                key={agent.id}
                type="button"
                onClick={() => toggleAgent(agent.id)}
                className={cn(
                  "w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-white/5 transition-colors",
                  isSelected && "bg-primary/10"
                )}
              >
                <AgentAvatar color={agent.color} name={agent.name} size="sm" />
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium text-foreground">{agent.name}</div>
                  <div className="text-xs text-muted-foreground truncate">{agent.description}</div>
                </div>
                {isSelected && <Check className="w-4 h-4 text-primary flex-shrink-0" />}
              </button>
            );
          })}
          {agents.length === 0 && (
            <div className="px-3 py-4 text-sm text-muted-foreground text-center">No agents found</div>
          )}
        </div>
      )}
    </div>
  );
}
