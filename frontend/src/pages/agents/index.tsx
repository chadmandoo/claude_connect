import { useState, useEffect, useCallback, useMemo } from "react";
import { useLocation } from "wouter";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { DataTable } from "@/components/ui/data-table";
import { AgentAvatar } from "@/components/AgentAvatar";
import { Bot, Plus, Trash2 } from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { type ColumnDef } from "@tanstack/react-table";
import { toast } from "sonner";

interface Agent {
  id: string;
  slug: string;
  name: string;
  description: string;
  color: string;
  icon: string;
  is_default: string;
  is_system: string;
  project_id: string;
  model: string;
  created_at: number;
}

export default function Agents() {
  const [, setLocation] = useLocation();
  const { request } = useWs();
  const [agents, setAgents] = useState<Agent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [deleteTarget, setDeleteTarget] = useState<string | null>(null);
  const [isDeletePending, setIsDeletePending] = useState(false);

  const loadAgents = useCallback(async () => {
    try {
      const resp = await request({ type: "agents.list" });
      setAgents((resp.agents as Agent[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  useEffect(() => {
    loadAgents();
  }, [loadAgents]);

  const handleDelete = (agentId: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setDeleteTarget(agentId);
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setIsDeletePending(true);
    try {
      await request({ type: "agents.delete", agent_id: deleteTarget });
      loadAgents();
      toast.success("Agent deleted");
    } catch (err: any) {
      toast.error(err?.message || "Failed to delete agent");
    } finally {
      setIsDeletePending(false);
      setDeleteTarget(null);
    }
  };

  const columns = useMemo<ColumnDef<Agent, unknown>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Agent",
        cell: ({ row }) => {
          const a = row.original;
          return (
            <div className="flex items-center gap-3">
              <AgentAvatar color={a.color} name={a.name} />
              <div className="min-w-0">
                <span className="text-sm font-medium text-foreground">{a.name}</span>
                <div className="text-xs text-muted-foreground truncate max-w-xs">{a.description}</div>
              </div>
            </div>
          );
        },
      },
      {
        accessorKey: "slug",
        header: "Slug",
        size: 100,
        cell: ({ row }) => (
          <code className="text-xs bg-background/50 px-1.5 py-0.5 rounded border border-border/50">
            @{row.original.slug}
          </code>
        ),
      },
      {
        id: "badges",
        header: "Type",
        size: 120,
        cell: ({ row }) => {
          const a = row.original;
          return (
            <div className="flex gap-1">
              {a.is_default === "1" && (
                <span className="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded bg-primary/10 text-primary border border-primary/20">
                  default
                </span>
              )}
              {a.is_system === "1" && (
                <span className="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded bg-slate-500/10 text-slate-400 border border-slate-500/20">
                  system
                </span>
              )}
            </div>
          );
        },
      },
      {
        accessorKey: "model",
        header: "Model",
        size: 120,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.model || "default"}
          </span>
        ),
      },
      {
        id: "actions",
        size: 60,
        enableSorting: false,
        cell: ({ row }) => {
          const a = row.original;
          if (a.is_system === "1") return null;
          return (
            <Button
              variant="ghost"
              size="icon"
              className="w-7 h-7 text-muted-foreground hover:text-red-400 hover:bg-red-400/10"
              onClick={(e) => handleDelete(a.id, e)}
              title="Delete"
            >
              <Trash2 className="w-3.5 h-3.5" />
            </Button>
          );
        },
      },
    ],
    []
  );

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-6xl mx-auto space-y-6 md:space-y-8">
          <div>
            <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
              Agents
            </h1>
            <p className="text-muted-foreground mt-1 hidden md:block">
              Manage AI agents with custom prompts and capabilities
            </p>
          </div>

          <DataTable
            columns={columns}
            data={agents}
            isLoading={isLoading}
            searchPlaceholder="Search agents..."
            onRowClick={(row) => setLocation(`/agents/${row.id}`)}
            emptyMessage="No agents found. Seed defaults to get started."
            headerActions={
              <div className="flex items-center gap-2">
                <Button
                  onClick={() => setLocation("/agents/new")}
                  className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl h-9"
                >
                  <Plus className="w-4 h-4 md:mr-2" />
                  <span className="hidden md:inline">New Agent</span>
                </Button>
              </div>
            }
          />
        </div>
      </div>
      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteTarget !== null} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Delete Agent</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            Are you sure you want to delete this agent? This action cannot be undone.
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
