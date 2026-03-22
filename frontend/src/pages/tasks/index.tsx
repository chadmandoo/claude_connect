import { useState, useEffect, useCallback } from "react";
import { Link } from "wouter";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Loader2,
  RotateCcw,
  Trash2,
  Clock,
  CheckCircle2,
  XCircle,
  ChevronDown,
  ChevronUp,
  ListFilter,
  MessageSquare,
} from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { formatDistanceToNow } from "date-fns";

interface Task {
  id: string;
  prompt: string;
  state: string;
  cost_usd: number;
  created_at: number;
  completed_at: number;
  claude_session_id: string;
  parent_task_id: string;
  conversation_id: string;
  project_id: string;
  workflow_template: string;
  error?: string;
  result?: string;
}

const STATE_CONFIG: Record<
  string,
  { icon: typeof Clock; color: string; bg: string; label: string }
> = {
  pending: {
    icon: Clock,
    color: "text-yellow-400",
    bg: "bg-yellow-400/10 border-yellow-400/20",
    label: "Pending",
  },
  running: {
    icon: Loader2,
    color: "text-blue-400",
    bg: "bg-blue-400/10 border-blue-400/20",
    label: "Running",
  },
  completed: {
    icon: CheckCircle2,
    color: "text-emerald-500",
    bg: "bg-emerald-500/10 border-emerald-500/20",
    label: "Completed",
  },
  failed: {
    icon: XCircle,
    color: "text-red-400",
    bg: "bg-red-400/10 border-red-400/20",
    label: "Failed",
  },
};

const FILTERS = ["all", "running", "pending", "failed", "completed"] as const;

export default function Tasks() {
  const { request, subscribe } = useWs();
  const [tasks, setTasks] = useState<Task[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [filter, setFilter] = useState<string>("all");
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [taskDetail, setTaskDetail] = useState<Record<string, Task>>({});
  const [deleteTarget, setDeleteTarget] = useState<Task | null>(null);

  const loadTasks = useCallback(async () => {
    try {
      const state = filter === "all" ? "" : filter;
      const resp = await request({ type: "tasks.list", state, limit: 50 });
      setTasks((resp.tasks as Task[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request, filter]);

  useEffect(() => {
    loadTasks();
  }, [loadTasks]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("task.state_changed", () => loadTasks()));
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadTasks]);

  // Auto-refresh running tasks every 10s
  useEffect(() => {
    const hasActive = tasks.some(
      (t) => t.state === "running" || t.state === "pending"
    );
    if (!hasActive) return;
    const interval = setInterval(loadTasks, 10000);
    return () => clearInterval(interval);
  }, [tasks, loadTasks]);

  const handleExpand = async (taskId: string) => {
    if (expandedId === taskId) {
      setExpandedId(null);
      return;
    }
    setExpandedId(taskId);
    if (!taskDetail[taskId]) {
      try {
        const resp = await request({ type: "tasks.get", task_id: taskId });
        if (resp.task) {
          setTaskDetail((prev) => ({
            ...prev,
            [taskId]: resp.task as Task,
          }));
        }
      } catch {
        // ignore
      }
    }
  };

  const handleRerun = async (task: Task) => {
    try {
      await request({
        type: "chat.send",
        prompt: task.prompt,
        conversation_id: task.conversation_id || null,
      });
    } catch {
      // ignore
    }
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    try {
      await request({ type: "tasks.delete", task_id: deleteTarget.id });
      loadTasks();
    } catch {
      // ignore
    }
    setDeleteTarget(null);
  };

  const filteredTasks =
    filter === "all" ? tasks : tasks.filter((t) => t.state === filter);

  const counts = {
    all: tasks.length,
    running: tasks.filter((t) => t.state === "running").length,
    pending: tasks.filter((t) => t.state === "pending").length,
    failed: tasks.filter((t) => t.state === "failed").length,
    completed: tasks.filter((t) => t.state === "completed").length,
  };

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-5xl mx-auto space-y-6 md:space-y-8">
          <div>
            <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
              Task Runner
            </h1>
            <p className="text-muted-foreground mt-1">
              Monitor, retry, and manage background tasks
            </p>
          </div>

          {/* Filter tabs */}
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1">
            {FILTERS.map((f) => {
              const count = counts[f];
              const isActive = filter === f;
              return (
                <button
                  key={f}
                  onClick={() => {
                    setFilter(f);
                    setIsLoading(true);
                  }}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors whitespace-nowrap ${
                    isActive
                      ? "bg-primary/10 text-primary"
                      : "text-muted-foreground hover:text-foreground hover:bg-white/5"
                  }`}
                >
                  <ListFilter className="w-3.5 h-3.5" />
                  <span className="capitalize">{f}</span>
                  {count > 0 && (
                    <span
                      className={`text-xs px-1.5 py-0.5 rounded-full ${
                        isActive
                          ? "bg-primary/20 text-primary"
                          : "bg-slate-800 text-slate-400"
                      }`}
                    >
                      {count}
                    </span>
                  )}
                </button>
              );
            })}
          </div>

          {isLoading ? (
            <div className="flex items-center justify-center h-48">
              <Loader2 className="w-8 h-8 text-primary animate-spin" />
            </div>
          ) : filteredTasks.length === 0 ? (
            <div className="text-center py-20 border border-dashed border-border/50 rounded-3xl bg-white/[0.02]">
              <div className="w-16 h-16 bg-slate-800 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-slate-700">
                <ListFilter className="w-8 h-8 text-muted-foreground" />
              </div>
              <h3 className="text-xl font-bold text-foreground mb-2">
                No {filter === "all" ? "" : filter} tasks
              </h3>
              <p className="text-muted-foreground max-w-sm mx-auto">
                Tasks will appear here as you interact with the agent.
              </p>
            </div>
          ) : (
            <div className="space-y-2">
              {filteredTasks.map((task) => {
                const config = STATE_CONFIG[task.state] || STATE_CONFIG.pending;
                const Icon = config.icon;
                const isExpanded = expandedId === task.id;
                const detail = taskDetail[task.id];
                const elapsed =
                  task.state === "running" && task.created_at
                    ? Math.floor(Date.now() / 1000) - task.created_at
                    : 0;

                return (
                  <div
                    key={task.id}
                    className="rounded-xl bg-card border border-border/50 overflow-hidden"
                  >
                    {/* Main row */}
                    <button
                      onClick={() => handleExpand(task.id)}
                      className="w-full flex items-center gap-3 p-3 md:p-4 text-left hover:bg-white/[0.02] transition-colors"
                    >
                      <div
                        className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${config.bg} border`}
                      >
                        <Icon
                          className={`w-4 h-4 ${config.color} ${
                            task.state === "running" ? "animate-spin" : ""
                          }`}
                        />
                      </div>

                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-foreground truncate">
                          {task.prompt}
                        </p>
                        <div className="flex items-center gap-3 mt-1 text-xs text-muted-foreground">
                          <span
                            className={`font-medium ${config.color} capitalize`}
                          >
                            {config.label}
                          </span>
                          {task.created_at > 0 && (
                            <span>
                              {formatDistanceToNow(
                                new Date(task.created_at * 1000),
                                { addSuffix: true }
                              )}
                            </span>
                          )}
                          {elapsed > 0 && (
                            <span className="text-blue-400">
                              {Math.floor(elapsed / 60)}m {elapsed % 60}s
                            </span>
                          )}
                          {task.cost_usd > 0 && (
                            <span>${task.cost_usd.toFixed(4)}</span>
                          )}
                          {task.conversation_id && (
                            <Link
                              href={`/conversations/${task.conversation_id}`}
                              className="inline-flex items-center gap-1 text-primary hover:underline"
                              onClick={(e: React.MouseEvent) =>
                                e.stopPropagation()
                              }
                            >
                              <MessageSquare className="w-3 h-3" />
                              Conversation
                            </Link>
                          )}
                          <span className="font-mono text-muted-foreground/50">
                            {task.id.substring(0, 8)}
                          </span>
                        </div>
                      </div>

                      <div className="flex items-center gap-1 flex-shrink-0">
                        {(task.state === "failed" ||
                          task.state === "completed") && (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="w-8 h-8 text-muted-foreground hover:text-primary"
                            onClick={(e) => {
                              e.stopPropagation();
                              handleRerun(task);
                            }}
                            title="Re-run"
                          >
                            <RotateCcw className="w-4 h-4" />
                          </Button>
                        )}
                        {task.state !== "running" && (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="w-8 h-8 text-muted-foreground hover:text-red-400"
                            onClick={(e) => {
                              e.stopPropagation();
                              setDeleteTarget(task);
                            }}
                            title="Delete"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </Button>
                        )}
                        {isExpanded ? (
                          <ChevronUp className="w-4 h-4 text-muted-foreground" />
                        ) : (
                          <ChevronDown className="w-4 h-4 text-muted-foreground" />
                        )}
                      </div>
                    </button>

                    {/* Expanded detail */}
                    {isExpanded && (
                      <div className="px-4 pb-4 border-t border-border/30 bg-background/30">
                        <div className="pt-3 space-y-3">
                          <div>
                            <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-semibold">
                              Full Prompt
                            </label>
                            <p className="text-sm text-foreground mt-1 whitespace-pre-wrap bg-slate-900/50 rounded-lg p-3 border border-border/30 max-h-40 overflow-auto">
                              {detail?.prompt || task.prompt}
                            </p>
                          </div>

                          {detail?.result && (
                            <div>
                              <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-semibold">
                                Result
                              </label>
                              <p className="text-sm text-foreground mt-1 whitespace-pre-wrap bg-slate-900/50 rounded-lg p-3 border border-border/30 max-h-60 overflow-auto">
                                {detail.result}
                              </p>
                            </div>
                          )}

                          {detail?.error && (
                            <div>
                              <label className="text-[10px] uppercase tracking-wider text-red-400 font-semibold">
                                Error
                              </label>
                              <p className="text-sm text-red-300 mt-1 whitespace-pre-wrap bg-red-950/30 rounded-lg p-3 border border-red-500/20">
                                {detail.error}
                              </p>
                            </div>
                          )}

                          <div className="flex flex-wrap gap-x-6 gap-y-2 text-xs text-muted-foreground pt-2">
                            <span>
                              <strong>ID:</strong>{" "}
                              <code className="text-slate-400">
                                {task.id}
                              </code>
                            </span>
                            {task.conversation_id && (
                              <span>
                                <strong>Conversation:</strong>{" "}
                                <Link
                                  href={`/conversations/${task.conversation_id}`}
                                  className="text-primary hover:underline"
                                >
                                  {task.conversation_id.substring(0, 8)}...
                                </Link>
                              </span>
                            )}
                            {task.project_id && (
                              <span>
                                <strong>Project:</strong> {task.project_id}
                              </span>
                            )}
                            {task.workflow_template && (
                              <span>
                                <strong>Template:</strong>{" "}
                                {task.workflow_template}
                              </span>
                            )}
                            {detail?.claude_session_id && (
                              <span>
                                <strong>Session:</strong>{" "}
                                <code className="text-slate-400">
                                  {detail.claude_session_id.substring(0, 8)}
                                </code>
                              </span>
                            )}
                          </div>

                          {(task.state === "failed" ||
                            task.state === "completed") && (
                            <div className="flex gap-2 pt-2">
                              <Button
                                size="sm"
                                className="rounded-lg"
                                onClick={() => handleRerun(task)}
                              >
                                <RotateCcw className="w-3.5 h-3.5 mr-1.5" />
                                Re-run Task
                              </Button>
                            </div>
                          )}
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>

      {/* Delete confirmation modal */}
      <Dialog
        open={deleteTarget !== null}
        onOpenChange={(open) => {
          if (!open) setDeleteTarget(null);
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Delete Task</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete this task? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          {deleteTarget && (
            <div className="bg-slate-900/50 rounded-lg p-3 border border-border/30 text-sm text-foreground line-clamp-3">
              {deleteTarget.prompt}
            </div>
          )}
          <DialogFooter>
            <Button
              variant="ghost"
              onClick={() => setDeleteTarget(null)}
              className="rounded-lg"
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              className="rounded-lg bg-red-600 hover:bg-red-500"
            >
              <Trash2 className="w-4 h-4 mr-1.5" />
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
