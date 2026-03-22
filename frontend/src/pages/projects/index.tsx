import { useState, useEffect, useCallback } from "react";
import { Link } from "wouter";
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
import { SquareKanban, Plus, Loader2, Calendar } from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { format } from "date-fns";

interface Project {
  id: string;
  name: string;
  description?: string;
  item_counts?: Record<string, number>;
  updated_at: number;
  created_at: number;
}

export default function Projects() {
  const { request, subscribe } = useWs();
  const [projects, setProjects] = useState<Project[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isOpen, setIsOpen] = useState(false);
  const [isPending, setIsPending] = useState(false);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");

  const loadProjects = useCallback(async () => {
    try {
      const resp = await request({ type: "projects.list" });
      setProjects((resp.projects as Project[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  useEffect(() => {
    loadProjects();
  }, [loadProjects]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("projects.created", () => loadProjects()));
    unsubs.push(subscribe("projects.updated", () => loadProjects()));
    unsubs.push(subscribe("projects.deleted", () => loadProjects()));
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadProjects]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    setIsPending(true);
    try {
      await request({
        type: "projects.create",
        name,
        description,
      });
      setIsOpen(false);
      setName("");
      setDescription("");
      loadProjects();
    } finally {
      setIsPending(false);
    }
  };

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-6xl mx-auto space-y-6 md:space-y-8">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
                Projects
              </h1>
              <p className="text-muted-foreground mt-1">
                Manage workflows with Kanban boards
              </p>
            </div>

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
              <DialogTrigger asChild>
                <Button className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl">
                  <Plus className="w-4 h-4 mr-2" />
                  New Project
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
                <form onSubmit={handleCreate}>
                  <DialogHeader>
                    <DialogTitle>Create Project</DialogTitle>
                  </DialogHeader>
                  <div className="py-6 space-y-4">
                    <div>
                      <label className="text-sm font-medium mb-1.5 block">
                        Project Name
                      </label>
                      <Input
                        autoFocus
                        placeholder="e.g. Q3 Roadmap"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        className="bg-background/50 border-border/50 rounded-xl focus-visible:ring-primary/30"
                      />
                    </div>
                    <div>
                      <label className="text-sm font-medium mb-1.5 block text-muted-foreground">
                        Description
                      </label>
                      <Input
                        placeholder="What is this project about?"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        className="bg-background/50 border-border/50 rounded-xl focus-visible:ring-primary/30"
                      />
                    </div>
                  </div>
                  <DialogFooter>
                    <Button
                      type="button"
                      variant="ghost"
                      onClick={() => setIsOpen(false)}
                      className="rounded-xl"
                    >
                      Cancel
                    </Button>
                    <Button
                      type="submit"
                      disabled={isPending || !name.trim()}
                      className="rounded-xl"
                    >
                      {isPending ? (
                        <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                      ) : null}
                      Create
                    </Button>
                  </DialogFooter>
                </form>
              </DialogContent>
            </Dialog>
          </div>

          {isLoading ? (
            <div className="flex items-center justify-center h-64">
              <Loader2 className="w-8 h-8 text-primary animate-spin" />
            </div>
          ) : projects.length === 0 ? (
            <div className="text-center py-24 border border-dashed border-border/50 rounded-3xl bg-white/[0.02]">
              <div className="w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-primary/20">
                <SquareKanban className="w-8 h-8 text-primary" />
              </div>
              <h3 className="text-xl font-bold text-foreground mb-2">
                No projects yet
              </h3>
              <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
                Create a project to start tracking tasks and issues.
              </p>
              <Button
                onClick={() => setIsOpen(true)}
                className="rounded-xl"
                variant="outline"
              >
                Create your first project
              </Button>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-5">
              {projects.map((project) => (
                <Link
                  key={project.id}
                  href={`/projects/${project.id}`}
                  className="group block rounded-2xl bg-card border border-border/50 hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300 overflow-hidden"
                >
                  <div className="h-2 w-full bg-gradient-to-r from-primary to-indigo-500 opacity-80 group-hover:opacity-100 transition-opacity" />
                  <div className="p-6">
                    <h3 className="text-xl font-bold text-foreground mb-2 group-hover:text-primary transition-colors">
                      {project.name}
                    </h3>
                    <p className="text-sm text-muted-foreground mb-6 line-clamp-2 min-h-[40px]">
                      {project.description || "No description provided."}
                    </p>
                    <div className="flex items-center justify-between pt-4 border-t border-border/50">
                      <div className="flex items-center gap-1.5 text-xs font-medium text-slate-300 bg-slate-800 px-2 py-1 rounded-md">
                        <SquareKanban className="w-3 h-3" />
                        {project.item_counts ? Object.values(project.item_counts).reduce((a, b) => a + b, 0) : 0} Items
                      </div>
                      <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Calendar className="w-3 h-3" />
                        {project.updated_at
                          ? format(
                              new Date(project.updated_at * 1000),
                              "MMM d, yyyy"
                            )
                          : ""}
                      </div>
                    </div>
                  </div>
                </Link>
              ))}
            </div>
          )}
        </div>
      </div>
    </Layout>
  );
}
