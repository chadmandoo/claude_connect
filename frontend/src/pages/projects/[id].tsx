import { useState, useEffect, useCallback } from "react";
import { useParams, Link, useLocation } from "wouter";
import {
  DragDropContext,
  Droppable,
  Draggable,
  type DropResult,
} from "@hello-pangea/dnd";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
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
import {
  ArrowLeft,
  Plus,
  Loader2,
  GripVertical,
  CircleDashed,
  Clock,
  CheckCircle2,
  AlertCircle,
  Settings,
  Trash2,
} from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { AgentAvatar } from "@/components/AgentAvatar";

interface WorkItem {
  id: string;
  title: string;
  description?: string;
  state: string;
  type?: string;
  priority?: string;
}

interface Project {
  id: string;
  name: string;
  description?: string;
  cwd?: string;
  default_agent_id?: string;
}

interface Agent {
  id: string;
  name: string;
  color: string;
  icon?: string;
}

const COLUMNS = [
  {
    id: "open",
    title: "Open",
    icon: CircleDashed,
    color: "text-slate-400",
  },
  {
    id: "in_progress",
    title: "In Progress",
    icon: Clock,
    color: "text-blue-400",
  },
  {
    id: "review",
    title: "Review",
    icon: CheckCircle2,
    color: "text-yellow-400",
  },
  {
    id: "done",
    title: "Done",
    icon: CheckCircle2,
    color: "text-emerald-500",
  },
];

const TYPE_COLORS: Record<string, string> = {
  bug: "bg-red-500/10 text-red-500 border-red-500/20",
  feature: "bg-primary/10 text-primary border-primary/20",
  task: "bg-slate-500/10 text-slate-300 border-slate-500/20",
  improvement: "bg-green-500/10 text-green-500 border-green-500/20",
};

const PRIORITY_COLORS: Record<string, string> = {
  low: "text-slate-400",
  normal: "text-blue-400",
  high: "text-orange-400",
  urgent: "text-red-500",
};

export default function ProjectKanban() {
  const { id } = useParams<{ id: string }>();
  const { request, subscribe } = useWs();
  const [, navigate] = useLocation();

  const [project, setProject] = useState<Project | null>(null);
  const [items, setItems] = useState<WorkItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isCreating, setIsCreating] = useState(false);
  const [formData, setFormData] = useState({
    title: "",
    description: "",
    type: "task",
    priority: "normal",
  });

  // Settings dialog state
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);
  const [isSavingSettings, setIsSavingSettings] = useState(false);
  const [isDeletingProject, setIsDeletingProject] = useState(false);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [settingsForm, setSettingsForm] = useState({
    name: "",
    description: "",
    cwd: "",
    default_agent_id: "",
  });

  const loadProject = useCallback(async () => {
    try {
      const resp = await request({
        type: "projects.get",
        project_id: id,
      });
      if (resp.project) {
        setProject(resp.project as Project);
      }
    } catch {
      // ignore
    }
  }, [id, request]);

  const loadItems = useCallback(async () => {
    try {
      const resp = await request({ type: "items.list", project_id: id });
      setItems((resp.items as WorkItem[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [id, request]);

  const loadAgents = useCallback(async () => {
    try {
      const resp = await request({ type: "agents.list" });
      setAgents((resp.agents as Agent[]) || []);
    } catch {
      // ignore
    }
  }, [request]);

  const openSettings = useCallback(() => {
    if (project) {
      setSettingsForm({
        name: project.name || "",
        description: project.description || "",
        cwd: project.cwd || "",
        default_agent_id: project.default_agent_id || "",
      });
    }
    loadAgents();
    setIsSettingsOpen(true);
  }, [project, loadAgents]);

  const handleSaveSettings = async () => {
    if (!project) return;
    setIsSavingSettings(true);
    try {
      await request({
        type: "projects.update",
        project_id: id,
        name: settingsForm.name,
        description: settingsForm.description,
        cwd: settingsForm.cwd,
        default_agent_id: settingsForm.default_agent_id || null,
      });
      setProject({
        ...project,
        name: settingsForm.name,
        description: settingsForm.description,
        cwd: settingsForm.cwd,
        default_agent_id: settingsForm.default_agent_id || undefined,
      });
      setIsSettingsOpen(false);
    } finally {
      setIsSavingSettings(false);
    }
  };

  const handleDeleteProject = async () => {
    if (!project || project.name === "General") return;
    setIsDeletingProject(true);
    try {
      await request({
        type: "projects.delete",
        project_id: id,
      });
      navigate("/projects");
    } finally {
      setIsDeletingProject(false);
    }
  };

  useEffect(() => {
    loadProject();
    loadItems();
  }, [loadProject, loadItems]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("items.created", () => loadItems()));
    unsubs.push(subscribe("items.updated", () => loadItems()));
    unsubs.push(subscribe("items.deleted", () => loadItems()));
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadItems]);

  const onDragEnd = async (result: DropResult) => {
    const { destination, source, draggableId } = result;
    if (!destination) return;
    if (
      destination.droppableId === source.droppableId &&
      destination.index === source.index
    )
      return;

    const newState = destination.droppableId;

    // Optimistic update
    setItems((prev) =>
      prev.map((item) =>
        item.id === draggableId ? { ...item, state: newState } : item
      )
    );

    try {
      await request({
        type: "items.update",
        item_id: draggableId,
        project_id: id,
        state: newState,
      });
    } catch {
      loadItems(); // revert on error
    }
  };

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.title.trim()) return;
    setIsCreating(true);
    try {
      await request({
        type: "items.create",
        project_id: id,
        title: formData.title,
        description: formData.description,
        item_type: formData.type,
        priority: formData.priority,
      });
      setIsCreateOpen(false);
      setFormData({
        title: "",
        description: "",
        type: "task",
        priority: "normal",
      });
      loadItems();
    } finally {
      setIsCreating(false);
    }
  };

  return (
    <Layout>
      <div className="flex flex-col h-full bg-background/50">
        <header className="h-12 md:h-16 flex-shrink-0 flex items-center justify-between px-3 md:px-6 border-b border-border/50 bg-background/80 backdrop-blur-md">
          <div className="flex items-center">
            <Link
              href="/projects"
              className="mr-4 p-2 -ml-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors"
            >
              <ArrowLeft className="w-5 h-5" />
            </Link>
            <h2 className="font-bold text-foreground">
              {project?.name || "Project"}
            </h2>
          </div>

          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant="ghost"
              onClick={openSettings}
              className="rounded-lg text-muted-foreground hover:text-foreground"
            >
              <Settings className="w-4 h-4" />
            </Button>

            <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
              <DialogTrigger asChild>
                <Button
                  size="sm"
                  className="rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground"
                >
                  <Plus className="w-4 h-4 mr-1.5" />
                  Add Item
                </Button>
              </DialogTrigger>
            <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
              <form onSubmit={handleCreate}>
                <DialogHeader>
                  <DialogTitle>New Work Item</DialogTitle>
                </DialogHeader>
                <div className="py-4 space-y-4">
                  <div>
                    <label className="text-xs font-medium mb-1 block">
                      Title
                    </label>
                    <Input
                      required
                      autoFocus
                      value={formData.title}
                      onChange={(e) =>
                        setFormData({ ...formData, title: e.target.value })
                      }
                      className="bg-background/50 border-border/50 rounded-lg"
                    />
                  </div>
                  <div>
                    <label className="text-xs font-medium mb-1 block">
                      Description
                    </label>
                    <Textarea
                      value={formData.description}
                      onChange={(e) =>
                        setFormData({
                          ...formData,
                          description: e.target.value,
                        })
                      }
                      className="bg-background/50 border-border/50 rounded-lg min-h-[100px]"
                    />
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="text-xs font-medium mb-1 block">
                        Type
                      </label>
                      <Select
                        value={formData.type}
                        onValueChange={(v) =>
                          setFormData({ ...formData, type: v })
                        }
                      >
                        <SelectTrigger className="bg-background/50 border-border/50 rounded-lg">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="task">Task</SelectItem>
                          <SelectItem value="bug">Bug</SelectItem>
                          <SelectItem value="feature">Feature</SelectItem>
                          <SelectItem value="improvement">
                            Improvement
                          </SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div>
                      <label className="text-xs font-medium mb-1 block">
                        Priority
                      </label>
                      <Select
                        value={formData.priority}
                        onValueChange={(v) =>
                          setFormData({ ...formData, priority: v })
                        }
                      >
                        <SelectTrigger className="bg-background/50 border-border/50 rounded-lg">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="low">Low</SelectItem>
                          <SelectItem value="normal">Normal</SelectItem>
                          <SelectItem value="high">High</SelectItem>
                          <SelectItem value="urgent">Urgent</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                </div>
                <DialogFooter>
                  <Button
                    type="button"
                    variant="ghost"
                    onClick={() => setIsCreateOpen(false)}
                    className="rounded-lg"
                  >
                    Cancel
                  </Button>
                  <Button
                    type="submit"
                    disabled={isCreating || !formData.title.trim()}
                    className="rounded-lg"
                  >
                    {isCreating ? (
                      <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                    ) : null}
                    Create
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          </div>

          {/* Settings Dialog */}
          <Dialog open={isSettingsOpen} onOpenChange={setIsSettingsOpen}>
            <DialogContent className="sm:max-w-lg bg-card border-border/50 rounded-2xl">
              <DialogHeader>
                <DialogTitle>Project Settings</DialogTitle>
              </DialogHeader>
              <div className="py-4 space-y-4">
                <div>
                  <label className="text-xs font-medium mb-1 block">
                    Project Name
                  </label>
                  <Input
                    value={settingsForm.name}
                    onChange={(e) =>
                      setSettingsForm({ ...settingsForm, name: e.target.value })
                    }
                    className="bg-background/50 border-border/50 rounded-lg"
                    placeholder="Project name"
                  />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block">
                    Description
                  </label>
                  <Textarea
                    value={settingsForm.description}
                    onChange={(e) =>
                      setSettingsForm({
                        ...settingsForm,
                        description: e.target.value,
                      })
                    }
                    className="bg-background/50 border-border/50 rounded-lg min-h-[80px]"
                    placeholder="Project description"
                  />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block">
                    Working Directory
                  </label>
                  <Input
                    value={settingsForm.cwd}
                    onChange={(e) =>
                      setSettingsForm({ ...settingsForm, cwd: e.target.value })
                    }
                    className="bg-background/50 border-border/50 rounded-lg font-mono text-sm"
                    placeholder="/path/to/project"
                  />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block">
                    Default Agent
                  </label>
                  <Select
                    value={settingsForm.default_agent_id || "none"}
                    onValueChange={(v) =>
                      setSettingsForm({
                        ...settingsForm,
                        default_agent_id: v === "none" ? "" : v,
                      })
                    }
                  >
                    <SelectTrigger className="bg-background/50 border-border/50 rounded-lg">
                      <SelectValue placeholder="No default agent" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No default agent</SelectItem>
                      {agents.map((agent) => (
                        <SelectItem key={agent.id} value={agent.id}>
                          <div className="flex items-center gap-2">
                            <AgentAvatar
                              color={agent.color}
                              name={agent.name}
                              size="sm"
                            />
                            <span>{agent.name}</span>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* Danger Zone */}
                <div className="pt-4 border-t border-border/50">
                  <h4 className="text-xs font-medium text-red-400 uppercase tracking-wider mb-3">
                    Danger Zone
                  </h4>
                  <div className="flex items-center justify-between p-3 rounded-lg border border-red-500/20 bg-red-500/5">
                    <div>
                      <p className="text-sm font-medium text-slate-200">
                        Delete this project
                      </p>
                      <p className="text-xs text-muted-foreground">
                        This action cannot be undone.
                      </p>
                    </div>
                    <Button
                      variant="destructive"
                      size="sm"
                      disabled={
                        isDeletingProject || project?.name === "General"
                      }
                      onClick={handleDeleteProject}
                      className="rounded-lg"
                    >
                      {isDeletingProject ? (
                        <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                      ) : (
                        <Trash2 className="w-4 h-4 mr-1.5" />
                      )}
                      Delete
                    </Button>
                  </div>
                  {project?.name === "General" && (
                    <p className="text-xs text-muted-foreground mt-2">
                      The General project cannot be deleted.
                    </p>
                  )}
                </div>
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() => setIsSettingsOpen(false)}
                  className="rounded-lg"
                >
                  Cancel
                </Button>
                <Button
                  type="button"
                  disabled={isSavingSettings || !settingsForm.name.trim()}
                  onClick={handleSaveSettings}
                  className="rounded-lg"
                >
                  {isSavingSettings ? (
                    <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                  ) : null}
                  Save
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </header>

        <div className="flex-1 overflow-x-auto overflow-y-hidden p-3 md:p-6 pb-20 md:pb-6">
          {isLoading ? (
            <div className="flex h-full items-center justify-center">
              <Loader2 className="w-8 h-8 text-primary animate-spin" />
            </div>
          ) : (
            <DragDropContext onDragEnd={onDragEnd}>
              <div className="flex gap-3 md:gap-6 h-full min-w-max items-start">
                {COLUMNS.map((col) => {
                  const columnItems = items.filter(
                    (item) => item.state === col.id
                  );
                  const Icon = col.icon;

                  return (
                    <div
                      key={col.id}
                      className="w-64 md:w-80 flex flex-col h-full max-h-full bg-slate-900/50 rounded-2xl border border-white/5 overflow-hidden"
                    >
                      <div className="p-4 flex items-center justify-between border-b border-white/5 bg-slate-900/80">
                        <div className="flex items-center gap-2">
                          <Icon className={`w-4 h-4 ${col.color}`} />
                          <h3 className="font-semibold text-sm">
                            {col.title}
                          </h3>
                        </div>
                        <span className="bg-slate-800 text-slate-300 text-xs font-bold px-2 py-0.5 rounded-full">
                          {columnItems.length}
                        </span>
                      </div>

                      <Droppable droppableId={col.id}>
                        {(provided, snapshot) => (
                          <div
                            ref={provided.innerRef}
                            {...provided.droppableProps}
                            className={`flex-1 overflow-y-auto p-3 space-y-3 transition-colors ${
                              snapshot.isDraggingOver ? "bg-primary/5" : ""
                            }`}
                          >
                            {columnItems.map((item, index) => (
                              <Draggable
                                key={item.id}
                                draggableId={item.id}
                                index={index}
                              >
                                {(provided, snapshot) => (
                                  <div
                                    ref={provided.innerRef}
                                    {...provided.draggableProps}
                                    {...provided.dragHandleProps}
                                    className={`p-4 rounded-xl bg-card border shadow-sm group transition-all ${
                                      snapshot.isDragging
                                        ? "border-primary shadow-primary/20 rotate-2 scale-105 z-50"
                                        : "border-border/50 hover:border-slate-600 hover:shadow-md"
                                    }`}
                                  >
                                    <div className="flex items-start justify-between mb-2">
                                      <div
                                        className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded border ${
                                          TYPE_COLORS[item.type || "task"] ||
                                          TYPE_COLORS.task
                                        }`}
                                      >
                                        {item.type || "task"}
                                      </div>
                                      <GripVertical className="w-4 h-4 text-slate-600 opacity-0 group-hover:opacity-100 transition-opacity cursor-grab" />
                                    </div>
                                    <h4 className="font-medium text-sm text-slate-200 leading-snug mb-3">
                                      {item.title}
                                    </h4>
                                    <div className="flex items-center justify-between mt-auto">
                                      <div className="flex items-center gap-1.5 text-xs font-medium text-slate-400">
                                        <AlertCircle
                                          className={`w-3.5 h-3.5 ${
                                            PRIORITY_COLORS[
                                              item.priority || "medium"
                                            ] || PRIORITY_COLORS.medium
                                          }`}
                                        />
                                        <span className="capitalize">
                                          {item.priority || "medium"}
                                        </span>
                                      </div>
                                      <span className="text-[10px] text-slate-500 font-mono">
                                        {item.id
                                          .split("-")[0]
                                          .substring(0, 6)}
                                      </span>
                                    </div>
                                  </div>
                                )}
                              </Draggable>
                            ))}
                            {provided.placeholder}
                          </div>
                        )}
                      </Droppable>
                    </div>
                  );
                })}
              </div>
            </DragDropContext>
          )}
        </div>
      </div>
    </Layout>
  );
}
