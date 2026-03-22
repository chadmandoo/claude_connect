import { useState, useEffect, useCallback, useMemo } from "react";
import { useLocation } from "wouter";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { AgentAvatar } from "@/components/AgentAvatar";
import { Plus, Loader2, Trash2, Shield, Database } from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { formatDistanceToNow } from "date-fns";
import { type ColumnDef } from "@tanstack/react-table";

interface Memory {
  id: string;
  content: string;
  category: string;
  importance: string;
  type: "core" | "project";
  agent_scope: string;
  source?: string;
  project_id?: string;
  last_surfaced_at?: number;
  updated_at: number;
  created_at: number;
}

interface Agent {
  id: string;
  slug: string;
  name: string;
  color: string;
}

interface Project {
  id: string;
  name: string;
}

const CATEGORIES = [
  { value: "fact", label: "Fact", description: "Objective information or knowledge" },
  { value: "preference", label: "Preference", description: "User preferences and working style" },
  { value: "context", label: "Context", description: "Situational context or background" },
  { value: "project", label: "Project", description: "Project-specific decisions or details" },
  { value: "rule", label: "Rule", description: "Behavioral rules or constraints" },
  { value: "conversation", label: "Conversation", description: "Insights from past conversations" },
];

const IMPORTANCE_STYLES: Record<string, string> = {
  low: "bg-slate-500/10 text-slate-400 border-slate-500/20",
  medium: "bg-blue-500/10 text-blue-400 border-blue-500/20",
  high: "bg-primary/20 text-primary border-primary/30 font-semibold",
};

export default function MemoryPage() {
  const { request, subscribe } = useWs();
  const [, navigate] = useLocation();
  const [memories, setMemories] = useState<Memory[]>([]);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isOpen, setIsOpen] = useState(false);
  const [isCreating, setIsCreating] = useState(false);
  const [typeFilter, setTypeFilter] = useState<"all" | "core" | "project">("all");
  const [deleteTarget, setDeleteTarget] = useState<string | null>(null);
  const [isDeletePending, setIsDeletePending] = useState(false);
  const [formData, setFormData] = useState({
    content: "",
    category: "",
    importance: "normal",
    type: "project" as "core" | "project",
    agent_scope: "*",
    project_id: "",
  });

  const agentMap = useMemo(() => {
    const map: Record<string, Agent> = {};
    agents.forEach((a) => (map[a.id] = a));
    return map;
  }, [agents]);

  const loadMemories = useCallback(async () => {
    try {
      const resp = await request({ type: "memory.list" });
      const mems = (resp.memories as Memory[]) || [];
      setMemories(mems);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  useEffect(() => {
    loadMemories();
    request({ type: "agents.list" })
      .then((r) => setAgents((r.agents as Agent[]) || []))
      .catch(() => {});
    request({ type: "projects.list" })
      .then((r) => setProjects((r.projects as Project[]) || []))
      .catch(() => {});
  }, [loadMemories, request]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("memory.created", () => loadMemories()));
    unsubs.push(subscribe("memory.updated", () => loadMemories()));
    unsubs.push(subscribe("memory.deleted", () => loadMemories()));
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadMemories]);

  const filteredMemories = useMemo(() => {
    if (typeFilter === "all") return memories;
    return memories.filter((m) => (m.type || "project") === typeFilter);
  }, [memories, typeFilter]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.content.trim() || !formData.category.trim()) return;
    setIsCreating(true);
    try {
      await request({
        type: "memory.create",
        content: formData.content,
        category: formData.category,
        importance: formData.importance,
        memory_type: formData.type,
        agent_scope: formData.agent_scope,
        project_id: formData.project_id || undefined,
      });
      setIsOpen(false);
      setFormData({ content: "", category: "", importance: "normal", type: "project", agent_scope: "*", project_id: "" });
      loadMemories();
    } finally {
      setIsCreating(false);
    }
  };

  const handleDelete = (id: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setDeleteTarget(id);
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setIsDeletePending(true);
    try {
      await request({ type: "memory.delete", memory_id: deleteTarget });
      loadMemories();
    } catch {
      // ignore
    } finally {
      setIsDeletePending(false);
      setDeleteTarget(null);
    }
  };

  const resolveAgentScope = (scope: string) => {
    if (!scope || scope === "*" || scope === "") return "All Agents";
    const ids = scope.split(",");
    const names = ids.map((id) => agentMap[id.trim()]?.name || id.substring(0, 8)).filter(Boolean);
    return names.join(", ");
  };

  const columns = useMemo<ColumnDef<Memory, unknown>[]>(
    () => [
      {
        accessorKey: "type",
        header: "Type",
        size: 80,
        cell: ({ row }) => {
          const t = row.original.type || "project";
          return t === "core" ? (
            <span className="flex items-center gap-1.5 text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border bg-amber-500/10 text-amber-400 border-amber-500/20 whitespace-nowrap">
              <Shield className="w-3 h-3" />
              Core
            </span>
          ) : (
            <span className="flex items-center gap-1.5 text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border bg-blue-500/10 text-blue-400 border-blue-500/20 whitespace-nowrap">
              <Database className="w-3 h-3" />
              Project
            </span>
          );
        },
      },
      {
        accessorKey: "category",
        header: "Category",
        size: 120,
        cell: ({ row }) => (
          <span className="text-xs font-semibold px-2 py-0.5 rounded bg-slate-800 text-slate-300 whitespace-nowrap">
            {row.original.category}
          </span>
        ),
      },
      {
        accessorKey: "content",
        header: "Content",
        cell: ({ row }) => (
          <span className="text-sm text-foreground line-clamp-2">
            {row.original.content}
          </span>
        ),
      },
      {
        accessorKey: "agent_scope",
        header: "Agent",
        size: 120,
        cell: ({ row }) => {
          const scope = row.original.agent_scope || "*";
          if (scope === "*" || scope === "") {
            return <span className="text-xs text-muted-foreground">All</span>;
          }
          const firstId = scope.split(",")[0].trim();
          const agent = agentMap[firstId];
          if (!agent) return <span className="text-xs text-muted-foreground">{scope.substring(0, 8)}</span>;
          const count = scope.split(",").length;
          return (
            <div className="flex items-center gap-1.5">
              <AgentAvatar color={agent.color} name={agent.name} size="sm" />
              <span className="text-xs text-foreground">{agent.name}</span>
              {count > 1 && <span className="text-[10px] text-muted-foreground">+{count - 1}</span>}
            </div>
          );
        },
      },
      {
        accessorKey: "importance",
        header: "Priority",
        size: 90,
        cell: ({ row }) => (
          <span
            className={`text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border whitespace-nowrap ${
              IMPORTANCE_STYLES[row.original.importance] || IMPORTANCE_STYLES.medium
            }`}
          >
            {row.original.importance}
          </span>
        ),
      },
      {
        accessorKey: "project_id",
        header: "Project",
        size: 110,
        cell: ({ row }) =>
          row.original.project_id ? (
            <span className="text-[10px] px-2 py-0.5 rounded bg-blue-500/10 text-blue-400 border border-blue-500/20 whitespace-nowrap">
              {row.original.project_id}
            </span>
          ) : (
            <span className="text-xs text-muted-foreground">General</span>
          ),
      },
      {
        accessorKey: "created_at",
        header: "Created",
        size: 120,
        sortingFn: "basic",
        cell: ({ row }) =>
          row.original.created_at ? (
            <span className="text-xs text-muted-foreground whitespace-nowrap">
              {formatDistanceToNow(new Date(row.original.created_at * 1000), { addSuffix: true })}
            </span>
          ) : null,
      },
      {
        id: "actions",
        size: 50,
        enableSorting: false,
        cell: ({ row }) => (
          <Button
            variant="ghost"
            size="icon"
            className="w-7 h-7 text-muted-foreground hover:text-red-400 hover:bg-red-400/10"
            onClick={(e) => handleDelete(row.original.id, e)}
          >
            <Trash2 className="w-3.5 h-3.5" />
          </Button>
        ),
      },
    ],
    [agentMap]
  );

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-6xl mx-auto space-y-6 md:space-y-8">
          <div>
            <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
              Agent Memory
            </h1>
            <p className="text-muted-foreground mt-1">
              Manage learned facts, rules, and context
            </p>
          </div>

          <DataTable
            columns={columns}
            data={filteredMemories}
            isLoading={isLoading}
            searchPlaceholder="Search memories..."
            onRowClick={(row) => navigate(`/memory/${row.id}`)}
            emptyMessage="No memories found. Add facts to give the agent permanent context."
            headerActions={
              <div className="flex items-center gap-2">
                {/* Type filter */}
                <div className="flex items-center rounded-xl border border-border/50 overflow-hidden">
                  {(["all", "core", "project"] as const).map((t) => (
                    <button
                      key={t}
                      onClick={() => setTypeFilter(t)}
                      className={`px-3 h-9 text-xs font-medium transition-colors ${
                        typeFilter === t
                          ? "bg-primary/10 text-primary"
                          : "text-muted-foreground hover:text-foreground hover:bg-white/5"
                      }`}
                    >
                      {t === "all" ? "All" : t === "core" ? "Core" : "Project"}
                    </button>
                  ))}
                </div>

                <Dialog open={isOpen} onOpenChange={setIsOpen}>
                  <DialogTrigger asChild>
                    <Button className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl h-9">
                      <Plus className="w-4 h-4 mr-2" />
                      Add Memory
                    </Button>
                  </DialogTrigger>
                  <DialogContent className="sm:max-w-lg bg-card border-border/50 rounded-2xl">
                    <form onSubmit={handleCreate}>
                      <DialogHeader>
                        <DialogTitle>Add to Memory</DialogTitle>
                      </DialogHeader>
                      <div className="py-6 space-y-4">
                        {/* Type selector */}
                        <div>
                          <label className="text-sm font-medium mb-1.5 block">Type</label>
                          <div className="flex gap-2">
                            <button
                              type="button"
                              onClick={() => setFormData({ ...formData, type: "core" })}
                              className={`flex-1 flex items-center gap-2 px-4 py-3 rounded-xl border text-left transition-colors ${
                                formData.type === "core"
                                  ? "border-amber-500/50 bg-amber-500/10 text-amber-400"
                                  : "border-border/50 text-muted-foreground hover:border-border"
                              }`}
                            >
                              <Shield className="w-4 h-4 flex-shrink-0" />
                              <div>
                                <div className="text-sm font-medium">Core</div>
                                <div className="text-[11px] opacity-70">Always included, never auto-pruned</div>
                              </div>
                            </button>
                            <button
                              type="button"
                              onClick={() => setFormData({ ...formData, type: "project" })}
                              className={`flex-1 flex items-center gap-2 px-4 py-3 rounded-xl border text-left transition-colors ${
                                formData.type === "project"
                                  ? "border-blue-500/50 bg-blue-500/10 text-blue-400"
                                  : "border-border/50 text-muted-foreground hover:border-border"
                              }`}
                            >
                              <Database className="w-4 h-4 flex-shrink-0" />
                              <div>
                                <div className="text-sm font-medium">Project</div>
                                <div className="text-[11px] opacity-70">Relevance-ranked, auto-managed</div>
                              </div>
                            </button>
                          </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                          <div>
                            <label className="text-sm font-medium mb-1.5 block">Category</label>
                            <Select
                              value={formData.category}
                              onValueChange={(v) => setFormData({ ...formData, category: v })}
                            >
                              <SelectTrigger className="bg-background/50 border-border/50 rounded-xl">
                                <SelectValue placeholder="Select category..." />
                              </SelectTrigger>
                              <SelectContent>
                                {CATEGORIES.map((cat) => (
                                  <SelectItem key={cat.value} value={cat.value}>
                                    {cat.label}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </div>
                          <div>
                            <label className="text-sm font-medium mb-1.5 block">Importance</label>
                            <Select
                              value={formData.importance}
                              onValueChange={(v) => setFormData({ ...formData, importance: v })}
                            >
                              <SelectTrigger className="bg-background/50 border-border/50 rounded-xl">
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="low">Low</SelectItem>
                                <SelectItem value="normal">Normal</SelectItem>
                                <SelectItem value="high">High</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>
                        </div>

                        <div>
                          <label className="text-sm font-medium mb-1.5 block">Content</label>
                          <Textarea
                            placeholder="What should the agent remember?"
                            value={formData.content}
                            onChange={(e) => setFormData({ ...formData, content: e.target.value })}
                            className="bg-background/50 border-border/50 rounded-xl min-h-[100px]"
                          />
                        </div>

                        {/* Agent scope (multi-select) */}
                        <div>
                          <label className="text-sm font-medium mb-1.5 block">Agents</label>
                          <div className="flex flex-wrap gap-2">
                            <button
                              type="button"
                              onClick={() => setFormData({ ...formData, agent_scope: "*" })}
                              className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                                formData.agent_scope === "*"
                                  ? "border-primary/50 bg-primary/10 text-primary"
                                  : "border-border/50 text-muted-foreground hover:border-border"
                              }`}
                            >
                              All Agents
                            </button>
                            {agents.map((agent) => {
                              const scopeIds = formData.agent_scope === "*" ? [] : formData.agent_scope.split(",").filter(Boolean);
                              const isSelected = scopeIds.includes(agent.id);
                              return (
                                <button
                                  key={agent.id}
                                  type="button"
                                  onClick={() => {
                                    let ids = formData.agent_scope === "*" ? [] : formData.agent_scope.split(",").filter(Boolean);
                                    if (isSelected) {
                                      ids = ids.filter((id) => id !== agent.id);
                                    } else {
                                      ids = [...ids, agent.id];
                                    }
                                    setFormData({ ...formData, agent_scope: ids.length === 0 ? "*" : ids.join(",") });
                                  }}
                                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                                    isSelected
                                      ? "border-primary/50 bg-primary/10 text-primary"
                                      : "border-border/50 text-muted-foreground hover:border-border"
                                  }`}
                                >
                                  <AgentAvatar color={agent.color} name={agent.name} size="sm" />
                                  {agent.name}
                                </button>
                              );
                            })}
                          </div>
                        </div>

                        {/* Project scope */}
                        <div>
                          <label className="text-sm font-medium mb-1.5 block">Project</label>
                          <Select
                            value={formData.project_id || "general"}
                            onValueChange={(v) => setFormData({ ...formData, project_id: v === "general" ? "" : v })}
                          >
                            <SelectTrigger className="bg-background/50 border-border/50 rounded-xl">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="general">General</SelectItem>
                              {projects.map((p) => (
                                <SelectItem key={p.id} value={p.id}>
                                  {p.name}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>
                      </div>
                      <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setIsOpen(false)} className="rounded-xl">
                          Cancel
                        </Button>
                        <Button
                          type="submit"
                          disabled={isCreating || !formData.content || !formData.category}
                          className="rounded-xl"
                        >
                          {isCreating ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
                          Save
                        </Button>
                      </DialogFooter>
                    </form>
                  </DialogContent>
                </Dialog>
              </div>
            }
          />
        </div>
      </div>
      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteTarget !== null} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Delete Memory</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            Are you sure you want to delete this memory? This action cannot be undone.
          </p>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setDeleteTarget(null)} disabled={isDeletePending}>
              Cancel
            </Button>
            <Button className="rounded-xl bg-red-500 hover:bg-red-600 text-white" onClick={confirmDelete} disabled={isDeletePending}>
              {isDeletePending ? "Deleting..." : "Delete"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
