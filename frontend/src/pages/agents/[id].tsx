import { useState, useEffect, useCallback } from "react";
import { useParams, useLocation, Link } from "wouter";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { AgentAvatar } from "@/components/AgentAvatar";
import { ArrowLeft, Save, Loader2 } from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { toast } from "sonner";

const COLORS = [
  "#6366f1", "#8b5cf6", "#ec4899", "#ef4444", "#f59e0b",
  "#10b981", "#06b6d4", "#3b82f6", "#64748b", "#f97316",
];

export default function AgentDetail() {
  const params = useParams<{ id: string }>();
  const id = params.id;
  const isNew = id === "new";
  const [, setLocation] = useLocation();
  const { request } = useWs();

  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [description, setDescription] = useState("");
  const [systemPrompt, setSystemPrompt] = useState("");
  const [model, setModel] = useState("");
  const [color, setColor] = useState("#6366f1");
  const [projectId, setProjectId] = useState("");
  const [isDefault, setIsDefault] = useState(false);
  const [isSystem, setIsSystem] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isLoading, setIsLoading] = useState(!isNew);

  useEffect(() => {
    if (!isNew && id) {
      request({ type: "agents.get", agent_id: id })
        .then((resp) => {
          const a = resp.agent as Record<string, any>;
          if (a) {
            setName(a.name || "");
            setSlug(a.slug || "");
            setDescription(a.description || "");
            setSystemPrompt(a.system_prompt || "");
            setModel(a.model || "");
            setColor(a.color || "#6366f1");
            setProjectId(a.project_id || "");
            setIsDefault(a.is_default === "1" || a.is_default === true);
            setIsSystem(a.is_system === "1" || a.is_system === true);
          }
        })
        .catch(() => toast.error("Failed to load agent"))
        .finally(() => setIsLoading(false));
    }
  }, [id, isNew, request]);

  const handleSave = async () => {
    if (!name.trim() || !slug.trim()) {
      toast.error("Name and slug are required");
      return;
    }
    setIsSaving(true);
    try {
      if (isNew) {
        const resp = await request({
          type: "agents.create",
          slug: slug.trim(),
          name: name.trim(),
          description,
          system_prompt: systemPrompt,
          model,
          color,
          project_id: projectId || null,
          is_default: isDefault,
        });
        const created = resp.agent as Record<string, any>;
        toast.success("Agent created");
        if (created?.id) {
          setLocation(`/agents/${created.id}`);
        }
      } else {
        await request({
          type: "agents.update",
          agent_id: id,
          slug: slug.trim(),
          name: name.trim(),
          description,
          system_prompt: systemPrompt,
          model,
          color,
          project_id: projectId || null,
          is_default: isDefault,
        });
        toast.success("Agent saved");
      }
    } catch (err: any) {
      toast.error(err?.message || "Failed to save");
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return (
      <Layout>
        <div className="flex items-center justify-center h-full">
          <Loader2 className="w-8 h-8 animate-spin text-primary" />
        </div>
      </Layout>
    );
  }

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-3xl mx-auto space-y-6">
          {/* Header */}
          <div className="flex items-center gap-4">
            <Link
              href="/agents"
              className="p-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors"
            >
              <ArrowLeft className="w-5 h-5" />
            </Link>
            <AgentAvatar color={color} name={name || "?"} size="lg" />
            <div className="flex-1">
              <h1 className="text-2xl font-display font-bold text-foreground">
                {isNew ? "New Agent" : name || "Agent"}
              </h1>
              {!isNew && (
                <p className="text-sm text-muted-foreground">@{slug}</p>
              )}
            </div>
            <Button
              onClick={handleSave}
              disabled={isSaving || !name.trim() || !slug.trim()}
              className="rounded-xl"
            >
              {isSaving ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />}
              Save
            </Button>
          </div>

          {/* Fields */}
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium mb-1.5 block">Name</label>
                <Input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="My Agent"
                  className="bg-background/50 border-border/50 rounded-xl"
                />
              </div>
              <div>
                <label className="text-sm font-medium mb-1.5 block">Slug</label>
                <Input
                  value={slug}
                  onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ""))}
                  placeholder="my-agent"
                  disabled={isSystem}
                  className="bg-background/50 border-border/50 rounded-xl"
                />
              </div>
            </div>

            <div>
              <label className="text-sm font-medium mb-1.5 block">Description</label>
              <Input
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="What does this agent do?"
                className="bg-background/50 border-border/50 rounded-xl"
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium mb-1.5 block">Model</label>
                <Input
                  value={model}
                  onChange={(e) => setModel(e.target.value)}
                  placeholder="Leave empty for default"
                  className="bg-background/50 border-border/50 rounded-xl"
                />
              </div>
              <div>
                <label className="text-sm font-medium mb-1.5 block">Color</label>
                <div className="flex gap-1.5 flex-wrap">
                  {COLORS.map((c) => (
                    <button
                      key={c}
                      type="button"
                      onClick={() => setColor(c)}
                      className={`w-7 h-7 rounded-lg transition-all ${color === c ? "ring-2 ring-white ring-offset-2 ring-offset-background scale-110" : "hover:scale-105"}`}
                      style={{ backgroundColor: c }}
                    />
                  ))}
                </div>
              </div>
            </div>

            <div className="flex items-center gap-4">
              <label className="flex items-center gap-2 text-sm cursor-pointer">
                <input
                  type="checkbox"
                  checked={isDefault}
                  onChange={(e) => setIsDefault(e.target.checked)}
                  className="rounded"
                />
                Default agent
              </label>
            </div>

            <div>
              <label className="text-sm font-medium mb-1.5 block">System Prompt</label>
              <Textarea
                value={systemPrompt}
                onChange={(e) => setSystemPrompt(e.target.value)}
                placeholder="Enter the agent's system prompt..."
                className="min-h-[300px] bg-background/50 border-border/50 rounded-xl font-mono text-sm"
                rows={15}
              />
            </div>
          </div>
        </div>
      </div>
    </Layout>
  );
}
