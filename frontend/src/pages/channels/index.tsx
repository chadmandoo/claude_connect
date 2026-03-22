import { useState, useEffect, useCallback, useMemo } from "react";
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
import { Hash, Plus, Loader2 } from "lucide-react";
import { AgentAvatar } from "@/components/AgentAvatar";
import { useWs } from "@/hooks/useWebSocket";

interface Channel {
  id: string;
  name: string;
  description?: string;
  member_count: number;
  created_at: number;
  agents?: Agent[];
}

interface Agent {
  id: string;
  slug: string;
  name: string;
  color: string;
  is_active_default?: boolean;
}

export default function Channels() {
  const { request, subscribe } = useWs();
  const [channels, setChannels] = useState<Channel[]>([]);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isOpen, setIsOpen] = useState(false);
  const [isPending, setIsPending] = useState(false);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [selectedAgentIds, setSelectedAgentIds] = useState<string[]>([]);

  const loadChannels = useCallback(async () => {
    try {
      const resp = await request({ type: "channels.list" });
      setChannels((resp.channels as Channel[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  useEffect(() => {
    loadChannels();
    request({ type: "agents.list" })
      .then((r) => setAgents((r.agents as Agent[]) || []))
      .catch(() => {});
  }, [loadChannels, request]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("channels.created", () => loadChannels()));
    unsubs.push(subscribe("channels.deleted", () => loadChannels()));
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadChannels]);

  const toggleAgent = (agentId: string) => {
    setSelectedAgentIds((prev) =>
      prev.includes(agentId) ? prev.filter((id) => id !== agentId) : [...prev, agentId]
    );
  };

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    setIsPending(true);
    try {
      const resp = await request({
        type: "channels.create",
        name: name.toLowerCase().replace(/\s+/g, "-"),
        description,
        agent_ids: selectedAgentIds,
      });
      setIsOpen(false);
      setName("");
      setDescription("");
      setSelectedAgentIds([]);
      loadChannels();
    } finally {
      setIsPending(false);
    }
  };

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-5xl mx-auto space-y-6 md:space-y-8">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
                Rooms
              </h1>
              <p className="text-muted-foreground mt-1">
                Chat with agents in dedicated spaces
              </p>
            </div>

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
              <DialogTrigger asChild>
                <Button className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl">
                  <Plus className="w-4 h-4 mr-2" />
                  New Room
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
                <form onSubmit={handleCreate}>
                  <DialogHeader>
                    <DialogTitle>Create a room</DialogTitle>
                  </DialogHeader>
                  <div className="py-6 space-y-4">
                    <div>
                      <label className="text-sm font-medium mb-1.5 block">Name</label>
                      <div className="relative">
                        <Hash className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <Input
                          autoFocus
                          placeholder="e.g. dev-chat"
                          value={name}
                          onChange={(e) => setName(e.target.value)}
                          className="pl-9 bg-background/50 border-border/50 rounded-xl"
                        />
                      </div>
                    </div>
                    <div>
                      <label className="text-sm font-medium mb-1.5 block text-muted-foreground">
                        Description (optional)
                      </label>
                      <Input
                        placeholder="What's this room about?"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        className="bg-background/50 border-border/50 rounded-xl"
                      />
                    </div>
                    <div>
                      <label className="text-sm font-medium mb-1.5 block">Agents</label>
                      <p className="text-xs text-muted-foreground mb-2">
                        Select agents for this room. First selected becomes the default.
                      </p>
                      <div className="flex flex-wrap gap-2">
                        {agents.map((agent) => {
                          const isSelected = selectedAgentIds.includes(agent.id);
                          const isFirst = selectedAgentIds[0] === agent.id;
                          return (
                            <button
                              key={agent.id}
                              type="button"
                              onClick={() => toggleAgent(agent.id)}
                              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                                isSelected
                                  ? isFirst
                                    ? "border-amber-500/50 bg-amber-500/10 text-amber-400"
                                    : "border-primary/50 bg-primary/10 text-primary"
                                  : "border-border/50 text-muted-foreground hover:border-border"
                              }`}
                            >
                              <AgentAvatar color={agent.color} name={agent.name} size="sm" />
                              {agent.name}
                              {isFirst && <span className="text-[9px] opacity-60 ml-1">default</span>}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  </div>
                  <DialogFooter>
                    <Button type="button" variant="ghost" onClick={() => setIsOpen(false)} className="rounded-xl">
                      Cancel
                    </Button>
                    <Button type="submit" disabled={isPending || !name.trim()} className="rounded-xl">
                      {isPending ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
                      Create Room
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
          ) : channels.length === 0 ? (
            <div className="text-center py-24 border border-dashed border-border/50 rounded-3xl bg-white/[0.02]">
              <div className="w-16 h-16 bg-slate-800 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-slate-700">
                <Hash className="w-8 h-8 text-muted-foreground" />
              </div>
              <h3 className="text-xl font-bold text-foreground mb-2">No rooms yet</h3>
              <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
                Rooms will be created automatically for each agent, or you can create custom ones.
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
              {channels.map((channel) => (
                <Link
                  key={channel.id}
                  href={`/channels/${channel.id}`}
                  className="group p-5 rounded-2xl bg-card border border-border/50 hover:border-primary/50 hover:shadow-lg hover:shadow-primary/5 transition-all duration-300"
                >
                  <div className="flex items-center gap-2 mb-2">
                    <Hash className="w-5 h-5 text-primary flex-shrink-0" />
                    <h3 className="text-lg font-bold text-foreground group-hover:text-primary transition-colors truncate">
                      {channel.name}
                    </h3>
                  </div>

                  {channel.description && (
                    <p className="text-sm text-muted-foreground mb-3 line-clamp-2 min-h-[40px]">
                      {channel.description}
                    </p>
                  )}

                  {/* Agent avatars */}
                  {channel.agents && channel.agents.length > 0 && (
                    <div className="flex items-center gap-1.5 mt-2">
                      {channel.agents.map((agent) => (
                        <div key={agent.id} title={agent.name}>
                          <AgentAvatar color={agent.color} name={agent.name} size="sm" />
                        </div>
                      ))}
                      <span className="text-xs text-muted-foreground ml-1">
                        {channel.agents.length === 1
                          ? channel.agents[0].name
                          : `${channel.agents.length} agents`}
                      </span>
                    </div>
                  )}
                </Link>
              ))}
            </div>
          )}
        </div>
      </div>
    </Layout>
  );
}
