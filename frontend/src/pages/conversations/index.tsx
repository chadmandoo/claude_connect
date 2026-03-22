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
import { ChevronsUpDown, Check } from "lucide-react";
import {
  MessageSquare,
  Plus,
  Archive,
  Trash2,
  Eye,
  EyeOff,
} from "lucide-react";
import { formatDistanceToNow } from "date-fns";
import { useWs } from "@/hooks/useWebSocket";
import { type ColumnDef } from "@tanstack/react-table";

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

interface Conversation {
  id: string;
  title?: string;
  summary?: string;
  message_count?: number;
  turn_count?: number;
  updated_at: number;
  created_at: number;
  type?: string;
  project_id?: string;
  state?: string;
  first_message?: string;
  total_cost_usd?: number;
  agent_id?: string;
  agent_name?: string;
  agent_slug?: string;
  agent_color?: string;
  agent_icon?: string;
}

const TYPE_STYLES: Record<string, string> = {
  task: "bg-blue-500/10 text-blue-400 border-blue-500/20",
  discussion: "bg-green-500/10 text-green-400 border-green-500/20",
  brainstorm: "bg-purple-500/10 text-purple-400 border-purple-500/20",
  planning: "bg-yellow-500/10 text-yellow-400 border-yellow-500/20",
  check_in: "bg-orange-500/10 text-orange-400 border-orange-500/20",
};

export default function Conversations() {
  const [, setLocation] = useLocation();
  const { request, subscribe } = useWs();
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showArchived, setShowArchived] = useState(false);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [selectedAgentId, setSelectedAgentId] = useState<string>("");
  const [agentPickerOpen, setAgentPickerOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<string | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  const loadConversations = useCallback(async () => {
    try {
      const resp = await request({
        type: "conversations.list",
        show_archived: showArchived,
      });
      setConversations((resp.conversations as Conversation[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request, showArchived]);

  useEffect(() => {
    loadConversations();
  }, [loadConversations]);

  // Load agents and default to "general"
  useEffect(() => {
    request({ type: "agents.list" })
      .then((resp) => {
        const list = (resp.agents as Agent[]) || [];
        setAgents(list);
        const general = list.find((a) => a.slug === "general");
        if (general) {
          setSelectedAgentId(general.id);
        } else if (list.length > 0) {
          setSelectedAgentId(list[0].id);
        }
      })
      .catch(() => {});
  }, [request]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(
      subscribe("conversations.archived", () => loadConversations())
    );
    unsubs.push(
      subscribe("conversations.deleted", () => loadConversations())
    );
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadConversations]);

  const handleNewChat = () => {
    if (!selectedAgentId) return;
    setLocation(`/conversations/new?agent=${selectedAgentId}`);
  };

  const selectedAgent = agents.find((a) => a.id === selectedAgentId);

  const handleArchive = async (convId: string, e: React.MouseEvent) => {
    e.stopPropagation();
    await request({ type: "conversations.archive", conversation_id: convId });
    loadConversations();
  };

  const handleDelete = (convId: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setDeleteTarget(convId);
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setIsDeleting(true);
    try {
      await request({
        type: "conversations.delete",
        conversation_id: deleteTarget,
      });
      loadConversations();
    } finally {
      setIsDeleting(false);
      setDeleteTarget(null);
    }
  };

  const columns = useMemo<ColumnDef<Conversation, unknown>[]>(
    () => [
      {
        accessorFn: (row) =>
          row.title || row.summary || row.first_message || `Chat ${row.id.substring(0, 8)}`,
        id: "title",
        header: "Title",
        cell: ({ row }) => {
          const conv = row.original;
          const isArchived =
            conv.state === "completed" || conv.state === "archived";
          return (
            <div className={`flex items-center gap-2.5 ${isArchived ? "opacity-60" : ""}`}>
              {conv.agent_name ? (
                <AgentAvatar color={conv.agent_color || "#6366f1"} name={conv.agent_name} size="md" />
              ) : (
                <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary flex-shrink-0">
                  <MessageSquare className="w-4 h-4" />
                </div>
              )}
              <div className="min-w-0">
                <span className="text-sm font-medium text-foreground line-clamp-1">
                  {conv.title ||
                    conv.summary ||
                    conv.first_message ||
                    `Chat ${conv.id.substring(0, 8)}`}
                </span>
              </div>
            </div>
          );
        },
      },
      {
        accessorKey: "type",
        header: "Type",
        size: 100,
        cell: ({ row }) => {
          const t = row.original.type || "task";
          return (
            <span
              className={`text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border whitespace-nowrap ${
                TYPE_STYLES[t] || TYPE_STYLES.task
              }`}
            >
              {t}
            </span>
          );
        },
      },
      {
        accessorFn: (row) => row.agent_name || "",
        id: "agent",
        header: "Agent",
        size: 120,
        cell: ({ row }) => {
          const conv = row.original;
          if (!conv.agent_name) {
            return <span className="text-xs text-muted-foreground">-</span>;
          }
          return (
            <div className="flex items-center gap-1.5">
              <AgentAvatar color={conv.agent_color || "#6366f1"} name={conv.agent_name} size="sm" />
              <span className="text-xs text-foreground truncate">{conv.agent_name}</span>
            </div>
          );
        },
      },
      {
        accessorKey: "project_id",
        header: "Project",
        size: 110,
        cell: ({ row }) => {
          const pid = row.original.project_id;
          return pid && pid !== "general" ? (
            <span className="text-[10px] px-2 py-0.5 rounded bg-blue-500/10 text-blue-400 border border-blue-500/20 whitespace-nowrap">
              {pid}
            </span>
          ) : (
            <span className="text-xs text-muted-foreground">General</span>
          );
        },
      },
      {
        accessorFn: (row) => row.turn_count || row.message_count || 0,
        id: "messages",
        header: "Msgs",
        size: 70,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.turn_count || row.original.message_count || 0}
          </span>
        ),
      },
      {
        accessorKey: "state",
        header: "State",
        size: 90,
        cell: ({ row }) => {
          const state = row.original.state || "active";
          const isArchived =
            state === "completed" || state === "archived";
          return (
            <span
              className={`text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border whitespace-nowrap ${
                isArchived
                  ? "bg-slate-500/10 text-slate-400 border-slate-500/20"
                  : "bg-green-500/10 text-green-400 border-green-500/20"
              }`}
            >
              {state}
            </span>
          );
        },
      },
      {
        accessorKey: "updated_at",
        header: "Updated",
        size: 120,
        sortingFn: "basic",
        cell: ({ row }) =>
          row.original.updated_at ? (
            <span className="text-xs text-muted-foreground whitespace-nowrap">
              {formatDistanceToNow(
                new Date(Number(row.original.updated_at) * 1000),
                { addSuffix: true }
              )}
            </span>
          ) : null,
      },
      {
        id: "actions",
        size: 70,
        enableSorting: false,
        cell: ({ row }) => {
          const conv = row.original;
          const isArchived =
            conv.state === "completed" || conv.state === "archived";
          return (
            <div className="flex items-center gap-0.5">
              {!isArchived && (
                <Button
                  variant="ghost"
                  size="icon"
                  className="w-7 h-7 text-muted-foreground hover:text-yellow-400 hover:bg-yellow-400/10"
                  onClick={(e) => handleArchive(conv.id, e)}
                  title="Archive"
                >
                  <Archive className="w-3.5 h-3.5" />
                </Button>
              )}
              <Button
                variant="ghost"
                size="icon"
                className="w-7 h-7 text-muted-foreground hover:text-red-400 hover:bg-red-400/10"
                onClick={(e) => handleDelete(conv.id, e)}
                title="Delete"
              >
                <Trash2 className="w-3.5 h-3.5" />
              </Button>
            </div>
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
              Conversations
            </h1>
            <p className="text-muted-foreground mt-1 hidden md:block">
              Chat directly with your AI agent
            </p>
          </div>

          <DataTable
            columns={columns}
            data={conversations}
            isLoading={isLoading}
            searchPlaceholder="Search conversations..."
            onRowClick={(row) => setLocation(`/conversations/${row.id}`)}
            emptyMessage={
              showArchived
                ? "No archived conversations."
                : "No conversations yet. Start a new chat!"
            }
            headerActions={
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  className={`rounded-lg text-xs h-9 ${
                    showArchived ? "text-primary" : "text-muted-foreground"
                  }`}
                  onClick={() => {
                    setShowArchived(!showArchived);
                    setIsLoading(true);
                  }}
                >
                  {showArchived ? (
                    <Eye className="w-3.5 h-3.5 mr-1" />
                  ) : (
                    <EyeOff className="w-3.5 h-3.5 mr-1" />
                  )}
                  <span className="hidden sm:inline">Archived</span>
                </Button>

                {/* Agent selector */}
                <div className="relative">
                  <button
                    type="button"
                    onClick={() => setAgentPickerOpen(!agentPickerOpen)}
                    className="flex items-center gap-2 px-3 h-9 rounded-xl border border-border/50 bg-background/50 text-sm hover:border-primary/50 transition-colors"
                  >
                    {selectedAgent ? (
                      <>
                        <AgentAvatar color={selectedAgent.color} name={selectedAgent.name} size="sm" />
                        <span className="text-foreground hidden sm:inline">{selectedAgent.name}</span>
                      </>
                    ) : (
                      <span className="text-muted-foreground">Agent</span>
                    )}
                    <ChevronsUpDown className="w-3.5 h-3.5 text-muted-foreground" />
                  </button>

                  {agentPickerOpen && (
                    <>
                      <div className="fixed inset-0 z-40" onClick={() => setAgentPickerOpen(false)} />
                      <div className="absolute right-0 z-50 mt-1 w-64 rounded-xl border border-border/50 bg-card shadow-xl max-h-64 overflow-y-auto">
                        {agents.map((agent) => (
                          <button
                            key={agent.id}
                            type="button"
                            onClick={() => {
                              setSelectedAgentId(agent.id);
                              setAgentPickerOpen(false);
                            }}
                            className={`w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-white/5 transition-colors ${
                              agent.id === selectedAgentId ? "bg-primary/10" : ""
                            }`}
                          >
                            <AgentAvatar color={agent.color} name={agent.name} size="sm" />
                            <div className="flex-1 min-w-0">
                              <div className="text-sm font-medium text-foreground">{agent.name}</div>
                              <div className="text-xs text-muted-foreground truncate">{agent.description}</div>
                            </div>
                            {agent.id === selectedAgentId && <Check className="w-4 h-4 text-primary flex-shrink-0" />}
                          </button>
                        ))}
                      </div>
                    </>
                  )}
                </div>

                <Button
                  onClick={handleNewChat}
                  disabled={!selectedAgentId}
                  className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl h-9"
                >
                  <Plus className="w-4 h-4 md:mr-2" />
                  <span className="hidden md:inline">New Chat</span>
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
            <DialogTitle>Delete Conversation</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            Are you sure you want to delete this conversation permanently? This action cannot be undone.
          </p>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setDeleteTarget(null)} disabled={isDeleting}>
              Cancel
            </Button>
            <Button
              className="rounded-xl bg-red-500 hover:bg-red-600 text-white"
              onClick={confirmDelete}
              disabled={isDeleting}
            >
              {isDeleting ? "Deleting..." : "Delete"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
