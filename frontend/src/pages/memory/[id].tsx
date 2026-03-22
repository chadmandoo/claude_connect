import { useState, useEffect, useCallback } from "react";
import { useParams, Link, useLocation } from "wouter";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  ArrowLeft,
  BrainCircuit,
  Loader2,
  Save,
  Trash2,
  Copy,
  Check,
  Shield,
  Database,
} from "lucide-react";
import { AgentAvatar } from "@/components/AgentAvatar";
import { useWs } from "@/hooks/useWebSocket";
import { formatDistanceToNow, format } from "date-fns";

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
  updated_at?: number;
  created_at: number;
}

interface Agent {
  id: string;
  slug: string;
  name: string;
  color: string;
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
  normal: "bg-blue-500/10 text-blue-400 border-blue-500/20",
  medium: "bg-blue-500/10 text-blue-400 border-blue-500/20",
  high: "bg-primary/20 text-primary border-primary/30 font-semibold",
};

export default function MemoryDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { request } = useWs();
  const [, navigate] = useLocation();

  interface Project { id: string; name: string; }
  const [memory, setMemory] = useState<Memory | null>(null);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [copied, setCopied] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);

  // Edit form state
  const [editContent, setEditContent] = useState("");
  const [editCategory, setEditCategory] = useState("");
  const [editImportance, setEditImportance] = useState("normal");
  const [editType, setEditType] = useState<"core" | "project">("project");
  const [editAgentScope, setEditAgentScope] = useState("*");
  const [editProjectId, setEditProjectId] = useState("");

  const loadMemory = useCallback(async () => {
    try {
      const resp = await request({ type: "memory.get", memory_id: id });
      const mem = resp.memory as Memory;
      if (mem) {
        setMemory(mem);
        setEditContent(mem.content);
        setEditCategory(mem.category);
        setEditImportance(mem.importance || "normal");
        setEditType(mem.type || "project");
        setEditAgentScope(mem.agent_scope || "*");
        setEditProjectId(mem.project_id || "");
      }
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [id, request]);

  useEffect(() => {
    loadMemory();
    request({ type: "agents.list" })
      .then((r) => setAgents((r.agents as Agent[]) || []))
      .catch(() => {});
    request({ type: "projects.list" })
      .then((r) => setProjects((r.projects as Project[]) || []))
      .catch(() => {});
  }, [loadMemory, request]);

  // Track changes
  useEffect(() => {
    if (!memory) return;
    const changed =
      editContent !== memory.content ||
      editCategory !== memory.category ||
      editImportance !== (memory.importance || "normal") ||
      editType !== (memory.type || "project") ||
      editAgentScope !== (memory.agent_scope || "*") ||
      editProjectId !== (memory.project_id || "");
    setHasChanges(changed);
  }, [editContent, editCategory, editImportance, editType, editAgentScope, editProjectId, memory]);

  const handleSave = async () => {
    if (!memory || !hasChanges) return;
    setIsSaving(true);
    try {
      await request({
        type: "memory.update",
        memory_id: memory.id,
        content: editContent,
        category: editCategory,
        importance: editImportance,
        memory_type: editType,
        agent_scope: editAgentScope,
        project_id: editProjectId || null,
      });
      setMemory({
        ...memory,
        content: editContent,
        category: editCategory,
        importance: editImportance,
        type: editType,
        agent_scope: editAgentScope,
        project_id: editProjectId || undefined,
      });
      setHasChanges(false);
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!memory) return;
    setIsDeleting(true);
    try {
      await request({ type: "memory.delete", memory_id: memory.id });
      setShowDeleteConfirm(false);
      navigate("/memory");
    } finally {
      setIsDeleting(false);
    }
  };

  const copyId = () => {
    if (!memory) return;
    navigator.clipboard.writeText(memory.id);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };

  const resolveAgentScope = (scope: string) => {
    if (!scope || scope === "*" || scope === "") return "All Agents";
    const ids = scope.split(",");
    const agentMap: Record<string, Agent> = {};
    agents.forEach((a) => (agentMap[a.id] = a));
    return ids.map((id) => agentMap[id.trim()]?.name || id.substring(0, 8)).join(", ");
  };

  if (isLoading) {
    return (
      <Layout>
        <div className="flex items-center justify-center h-full">
          <Loader2 className="w-8 h-8 text-primary animate-spin" />
        </div>
      </Layout>
    );
  }

  if (!memory) {
    return (
      <Layout>
        <div className="flex flex-col items-center justify-center h-full gap-4 text-muted-foreground">
          <BrainCircuit className="w-12 h-12" />
          <p>Memory not found</p>
          <Link href="/memory">
            <Button variant="outline" className="rounded-xl">
              Back to Memory
            </Button>
          </Link>
        </div>
      </Layout>
    );
  }

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-3xl mx-auto space-y-6 md:space-y-8">
          {/* Header */}
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <Link
                href="/memory"
                className="p-2 -ml-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors"
              >
                <ArrowLeft className="w-5 h-5" />
              </Link>
              <div>
                <h1 className="text-xl md:text-2xl font-display font-bold text-foreground">
                  Memory Detail
                </h1>
                <p className="text-sm text-muted-foreground mt-0.5">
                  View and edit this memory entry
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="rounded-xl text-red-400 border-red-400/30 hover:bg-red-400/10 hover:text-red-400"
                onClick={() => setShowDeleteConfirm(true)}
              >
                <Trash2 className="w-4 h-4 mr-1" />
                Delete
              </Button>
              <Button size="sm" className="rounded-xl" onClick={handleSave} disabled={!hasChanges || isSaving}>
                {isSaving ? <Loader2 className="w-4 h-4 mr-1 animate-spin" /> : <Save className="w-4 h-4 mr-1" />}
                Save
              </Button>
            </div>
          </div>

          {/* Metadata Card */}
          <div className="p-5 rounded-2xl bg-card border border-border/50 space-y-4">
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">
                  Memory ID
                </label>
                <div className="flex items-center gap-1.5 mt-1">
                  <code className="text-xs font-mono text-foreground truncate">{memory.id}</code>
                  <button
                    onClick={copyId}
                    className="p-0.5 rounded hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors flex-shrink-0"
                  >
                    {copied ? <Check className="w-3 h-3 text-green-400" /> : <Copy className="w-3 h-3" />}
                  </button>
                </div>
              </div>
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">Type</label>
                <div className="mt-1">
                  {(memory.type || "project") === "core" ? (
                    <span className="inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border bg-amber-500/10 text-amber-400 border-amber-500/20">
                      <Shield className="w-3 h-3" /> Core
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border bg-blue-500/10 text-blue-400 border-blue-500/20">
                      <Database className="w-3 h-3" /> Project
                    </span>
                  )}
                </div>
              </div>
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">
                  Agent Scope
                </label>
                <p className="text-sm text-foreground mt-1">{resolveAgentScope(memory.agent_scope)}</p>
              </div>
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">Source</label>
                <p className="text-sm text-foreground mt-1 capitalize">{memory.source || "unknown"}</p>
              </div>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">Project</label>
                <p className="text-sm text-foreground mt-1">{memory.project_id || "General"}</p>
              </div>
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">Created</label>
                <p className="text-sm text-foreground mt-1">
                  {memory.created_at
                    ? formatDistanceToNow(new Date(memory.created_at * 1000), { addSuffix: true })
                    : "Unknown"}
                </p>
              </div>
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">
                  Last Surfaced
                </label>
                <p className="text-sm text-foreground mt-1">
                  {memory.last_surfaced_at && memory.last_surfaced_at > 0
                    ? formatDistanceToNow(new Date(memory.last_surfaced_at * 1000), { addSuffix: true })
                    : "Never"}
                </p>
              </div>
              <div>
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">Updated</label>
                <p className="text-sm text-foreground mt-1">
                  {memory.updated_at && memory.updated_at > 0
                    ? formatDistanceToNow(new Date(memory.updated_at * 1000), { addSuffix: true })
                    : "—"}
                </p>
              </div>
            </div>
          </div>

          {/* Edit Form */}
          <div className="space-y-5">
            {/* Type */}
            <div>
              <label className="text-sm font-medium mb-1.5 block text-foreground">Type</label>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setEditType("core")}
                  className={`flex-1 flex items-center gap-2 px-4 py-3 rounded-xl border text-left transition-colors ${
                    editType === "core"
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
                  onClick={() => setEditType("project")}
                  className={`flex-1 flex items-center gap-2 px-4 py-3 rounded-xl border text-left transition-colors ${
                    editType === "project"
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
              {/* Category */}
              <div>
                <label className="text-sm font-medium mb-1.5 block text-foreground">Category</label>
                <Select value={editCategory} onValueChange={setEditCategory}>
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

              {/* Importance */}
              <div>
                <label className="text-sm font-medium mb-1.5 block text-foreground">Importance</label>
                <Select value={editImportance} onValueChange={setEditImportance}>
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

            {/* Agent Scope (multi-select) */}
            <div>
              <label className="text-sm font-medium mb-1.5 block text-foreground">Agents</label>
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={() => setEditAgentScope("*")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                    editAgentScope === "*"
                      ? "border-primary/50 bg-primary/10 text-primary"
                      : "border-border/50 text-muted-foreground hover:border-border"
                  }`}
                >
                  All Agents
                </button>
                {agents.map((agent) => {
                  const scopeIds = editAgentScope === "*" ? [] : editAgentScope.split(",").filter(Boolean);
                  const isSelected = scopeIds.includes(agent.id);
                  return (
                    <button
                      key={agent.id}
                      type="button"
                      onClick={() => {
                        let ids = editAgentScope === "*" ? [] : editAgentScope.split(",").filter(Boolean);
                        if (isSelected) {
                          ids = ids.filter((i) => i !== agent.id);
                        } else {
                          ids = [...ids, agent.id];
                        }
                        setEditAgentScope(ids.length === 0 ? "*" : ids.join(","));
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

            {/* Project */}
            <div>
              <label className="text-sm font-medium mb-1.5 block text-foreground">Project</label>
              <Select value={editProjectId || "general"} onValueChange={(v) => setEditProjectId(v === "general" ? "" : v)}>
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

            {/* Content */}
            <div>
              <label className="text-sm font-medium mb-1.5 block text-foreground">Content</label>
              <Textarea
                value={editContent}
                onChange={(e) => setEditContent(e.target.value)}
                placeholder="Memory content..."
                className="bg-background/50 border-border/50 rounded-xl min-h-[200px] text-[15px] leading-relaxed"
              />
            </div>
          </div>

          {/* Sticky save bar when changes exist */}
          {hasChanges && (
            <div className="fixed bottom-16 md:bottom-4 left-0 right-0 z-20 px-4">
              <div className="max-w-3xl mx-auto">
                <div className="flex items-center justify-between p-3 rounded-2xl bg-card border border-primary/30 shadow-lg shadow-primary/10">
                  <span className="text-sm text-muted-foreground">You have unsaved changes</span>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="rounded-xl"
                      onClick={() => {
                        setEditContent(memory.content);
                        setEditCategory(memory.category);
                        setEditImportance(memory.importance || "normal");
                        setEditType(memory.type || "project");
                        setEditAgentScope(memory.agent_scope || "*");
                        setEditProjectId(memory.project_id || "");
                      }}
                    >
                      Discard
                    </Button>
                    <Button size="sm" className="rounded-xl" onClick={handleSave} disabled={isSaving}>
                      {isSaving ? <Loader2 className="w-4 h-4 mr-1 animate-spin" /> : <Save className="w-4 h-4 mr-1" />}
                      Save Changes
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Delete Confirmation Dialog */}
      <Dialog open={showDeleteConfirm} onOpenChange={setShowDeleteConfirm}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Delete Memory</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            Are you sure you want to delete this memory permanently? This action cannot be undone.
          </p>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setShowDeleteConfirm(false)} disabled={isDeleting}>
              Cancel
            </Button>
            <Button className="rounded-xl bg-red-500 hover:bg-red-600 text-white" onClick={handleDelete} disabled={isDeleting}>
              {isDeleting ? "Deleting..." : "Delete"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
