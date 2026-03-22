import { useState, useEffect, useCallback } from "react";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Switch } from "@/components/ui/switch";
import {
  Clock,
  Loader2,
  Play,
  Pause,
  Calendar,
  Timer,
  CheckCircle2,
  AlertCircle,
  Trash2,
} from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { formatDistanceToNow } from "date-fns";

interface ScheduledJob {
  id: string;
  name: string;
  description: string;
  schedule_type: string;
  schedule_seconds?: string;
  schedule_hour?: string;
  schedule_minute?: string;
  enabled: string;
  handler: string;
  last_run: string;
  next_run: string;
  last_result: string;
  last_duration: string;
  run_count: string;
}

function formatSchedule(job: ScheduledJob): string {
  if (job.schedule_type === "daily") {
    const h = String(job.schedule_hour || "0").padStart(2, "0");
    const m = String(job.schedule_minute || "0").padStart(2, "0");
    return `Daily at ${h}:${m}`;
  }
  const secs = Number(job.schedule_seconds || 3600);
  if (secs >= 86400) return `Every ${Math.round(secs / 86400)}d`;
  if (secs >= 3600) return `Every ${Math.round(secs / 3600)}h`;
  if (secs >= 60) return `Every ${Math.round(secs / 60)}m`;
  return `Every ${secs}s`;
}

export default function Scheduler() {
  const { request, subscribe } = useWs();
  const [jobs, setJobs] = useState<ScheduledJob[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const loadJobs = useCallback(async () => {
    try {
      const resp = await request({ type: "scheduler.list" });
      setJobs((resp.jobs as ScheduledJob[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  useEffect(() => {
    loadJobs();
  }, [loadJobs]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("scheduler.toggled", () => loadJobs()));
    unsubs.push(subscribe("scheduler.created", () => loadJobs()));
    unsubs.push(subscribe("scheduler.deleted", () => loadJobs()));
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadJobs]);

  const handleToggle = async (jobId: string, enabled: boolean) => {
    await request({ type: "scheduler.toggle", job_id: jobId, enabled });
    loadJobs();
  };

  const [deleteTarget, setDeleteTarget] = useState<string | null>(null);
  const [isDeletePending, setIsDeletePending] = useState(false);

  const handleDelete = (jobId: string) => {
    setDeleteTarget(jobId);
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setIsDeletePending(true);
    try {
      await request({ type: "scheduler.delete", job_id: deleteTarget });
      loadJobs();
    } finally {
      setIsDeletePending(false);
      setDeleteTarget(null);
    }
  };

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-5xl mx-auto space-y-6 md:space-y-8">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
                Scheduler
              </h1>
              <p className="text-muted-foreground mt-1">
                Manage scheduled tasks and background jobs
              </p>
            </div>
          </div>

          {isLoading ? (
            <div className="flex items-center justify-center h-64">
              <Loader2 className="w-8 h-8 text-primary animate-spin" />
            </div>
          ) : jobs.length === 0 ? (
            <div className="text-center py-24 border border-dashed border-border/50 rounded-3xl bg-white/[0.02]">
              <div className="w-16 h-16 bg-slate-800 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-slate-700">
                <Clock className="w-8 h-8 text-muted-foreground" />
              </div>
              <h3 className="text-xl font-bold text-foreground mb-2">
                No scheduled jobs
              </h3>
              <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
                Jobs will appear here once the scheduler starts.
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {jobs.map((job) => {
                const isEnabled = job.enabled === "1" || job.enabled === "true";
                const lastRun = Number(job.last_run || 0);
                const nextRun = Number(job.next_run || 0);
                const runCount = Number(job.run_count || 0);
                const lastDuration = Number(job.last_duration || 0);
                const lastResult = job.last_result || "";
                const isBuiltin = ["nightly", "cleanup", "supervisor_health", "memory_sync"].includes(job.handler);

                return (
                  <div
                    key={job.id}
                    className={`p-4 md:p-6 rounded-2xl border transition-all duration-300 ${
                      isEnabled
                        ? "bg-card border-border/50 hover:border-primary/30"
                        : "bg-background/50 border-border/30 opacity-60"
                    }`}
                  >
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex items-start gap-3 md:gap-4 flex-1 min-w-0">
                        <div
                          className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${
                            isEnabled
                              ? "bg-primary/20 text-primary"
                              : "bg-slate-800 text-slate-500"
                          }`}
                        >
                          {isEnabled ? (
                            <Play className="w-5 h-5" />
                          ) : (
                            <Pause className="w-5 h-5" />
                          )}
                        </div>
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2 mb-1">
                            <h3 className="font-bold text-foreground text-sm md:text-base truncate">
                              {job.name}
                            </h3>
                            <span className="text-[10px] bg-slate-800 text-slate-400 px-1.5 py-0.5 rounded uppercase tracking-wider flex-shrink-0">
                              {formatSchedule(job)}
                            </span>
                          </div>
                          <p className="text-sm text-muted-foreground line-clamp-1">
                            {job.description}
                          </p>

                          {/* Stats row */}
                          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-xs text-muted-foreground">
                            {lastRun > 0 && (
                              <span className="flex items-center gap-1">
                                <CheckCircle2 className="w-3 h-3 text-emerald-500" />
                                Last:{" "}
                                {formatDistanceToNow(
                                  new Date(lastRun * 1000),
                                  { addSuffix: true }
                                )}
                              </span>
                            )}
                            {nextRun > 0 && isEnabled && (
                              <span className="flex items-center gap-1">
                                <Timer className="w-3 h-3 text-blue-400" />
                                Next:{" "}
                                {nextRun * 1000 > Date.now()
                                  ? formatDistanceToNow(
                                      new Date(nextRun * 1000),
                                      { addSuffix: true }
                                    )
                                  : "due now"}
                              </span>
                            )}
                            {runCount > 0 && (
                              <span className="flex items-center gap-1">
                                <Calendar className="w-3 h-3" />
                                {runCount} runs
                              </span>
                            )}
                            {lastDuration > 0 && (
                              <span>{lastDuration}s</span>
                            )}
                          </div>

                          {/* Last result */}
                          {lastResult && (
                            <div className="mt-2 px-3 py-2 rounded-lg bg-background/50 border border-border/30 text-xs text-muted-foreground line-clamp-2">
                              {lastResult.startsWith("Error") ? (
                                <span className="flex items-center gap-1 text-red-400">
                                  <AlertCircle className="w-3 h-3" />
                                  {lastResult}
                                </span>
                              ) : (
                                lastResult
                              )}
                            </div>
                          )}
                        </div>
                      </div>

                      <div className="flex items-center gap-2 flex-shrink-0">
                        {!isBuiltin && (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="w-8 h-8 text-muted-foreground hover:text-red-400"
                            onClick={() => handleDelete(job.id)}
                          >
                            <Trash2 className="w-4 h-4" />
                          </Button>
                        )}
                        <Switch
                          checked={isEnabled}
                          onCheckedChange={(checked) =>
                            handleToggle(job.id, checked)
                          }
                        />
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteTarget !== null} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Delete Scheduled Job</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            Are you sure you want to delete this scheduled job? This action cannot be undone.
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
