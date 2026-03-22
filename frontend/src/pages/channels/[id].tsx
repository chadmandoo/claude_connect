import { useState, useRef, useEffect, useCallback } from "react";
import { useParams, Link, useLocation } from "wouter";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { ArrowLeft, Send, Hash, Loader2, Settings, Crown, Trash2 } from "lucide-react";
import { AgentAvatar } from "@/components/AgentAvatar";
import { useWs } from "@/hooks/useWebSocket";
import { format } from "date-fns";
import { MarkdownRenderer } from "@/components/MarkdownRenderer";

interface ChannelMessage {
  id: string;
  author: string;
  content: string;
  created_at: number;
  agent_id?: string;
}

interface Channel {
  id: string;
  name: string;
  description?: string;
}

interface Agent {
  id: string;
  slug: string;
  name: string;
  color: string;
  is_active_default?: boolean;
}

export default function ChannelDetail() {
  const { id } = useParams<{ id: string }>();
  const { request, subscribe } = useWs();
  const [, navigate] = useLocation();

  const [channel, setChannel] = useState<Channel | null>(null);
  const [messages, setMessages] = useState<ChannelMessage[]>([]);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [content, setContent] = useState("");
  const [isPending, setIsPending] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Settings dialog state
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [allAgents, setAllAgents] = useState<Agent[]>([]);
  const [editName, setEditName] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [isSavingName, setIsSavingName] = useState(false);
  const [isSavingDescription, setIsSavingDescription] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [togglingAgent, setTogglingAgent] = useState<string | null>(null);
  const [settingDefault, setSettingDefault] = useState<string | null>(null);

  const agentMap = new Map<string, Agent>();
  agents.forEach((a) => agentMap.set(a.id, a));
  // Also map by name for messages that don't have agent_id
  const agentByName = new Map<string, Agent>();
  agents.forEach((a) => agentByName.set(a.name, a));

  const channelAgentIds = new Set(agents.map((a) => a.id));

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  const loadChannel = useCallback(async () => {
    try {
      const resp = await request({
        type: "channels.detail",
        channel_id: id,
      });
      if (resp.channel) setChannel(resp.channel as Channel);
      if (resp.messages) setMessages(resp.messages as ChannelMessage[]);
      if (resp.agents) setAgents(resp.agents as Agent[]);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [id, request]);

  useEffect(() => {
    loadChannel();
  }, [loadChannel]);

  useEffect(() => {
    const unsub = subscribe("channels.message", (msg) => {
      if (msg.channel_id === id) {
        setMessages((prev) => [...prev, msg.message as ChannelMessage]);
      }
    });
    return unsub;
  }, [id, subscribe]);

  // Load all available agents when settings dialog opens
  useEffect(() => {
    if (settingsOpen) {
      request({ type: "agents.list" })
        .then((r) => setAllAgents((r.agents as Agent[]) || []))
        .catch(() => {});
      // Sync edit fields with current channel data
      setEditName(channel?.name || "");
      setEditDescription(channel?.description || "");
    }
  }, [settingsOpen, request, channel]);

  const handleSend = async () => {
    if (!content.trim() || isPending) return;
    setIsPending(true);
    try {
      await request({
        type: "channels.send",
        channel_id: id,
        content: content.trim(),
      });
      setContent("");
      setMessages((prev) => [
        ...prev,
        {
          id: `local-${Date.now()}`,
          author: "You",
          content: content.trim(),
          created_at: Math.floor(Date.now() / 1000),
        },
      ]);
    } finally {
      setIsPending(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  const getAgentForMessage = (msg: ChannelMessage): Agent | undefined => {
    if (msg.agent_id) return agentMap.get(msg.agent_id);
    return agentByName.get(msg.author);
  };

  const defaultAgent = agents.find((a) => a.is_active_default);

  // Settings handlers
  const handleUpdateName = async () => {
    if (!editName.trim() || editName === channel?.name) return;
    setIsSavingName(true);
    try {
      await request({
        type: "channels.update",
        channel_id: id,
        name: editName.trim(),
      });
      await loadChannel();
    } finally {
      setIsSavingName(false);
    }
  };

  const handleUpdateDescription = async () => {
    if (editDescription === (channel?.description || "")) return;
    setIsSavingDescription(true);
    try {
      await request({
        type: "channels.update",
        channel_id: id,
        description: editDescription.trim(),
      });
      await loadChannel();
    } finally {
      setIsSavingDescription(false);
    }
  };

  const handleToggleAgent = async (agentId: string) => {
    setTogglingAgent(agentId);
    try {
      if (channelAgentIds.has(agentId)) {
        await request({
          type: "rooms.remove_agent",
          channel_id: id,
          agent_id: agentId,
        });
      } else {
        await request({
          type: "rooms.add_agent",
          channel_id: id,
          agent_id: agentId,
        });
      }
      await loadChannel();
    } finally {
      setTogglingAgent(null);
    }
  };

  const handleSetDefault = async (agentId: string) => {
    setSettingDefault(agentId);
    try {
      await request({
        type: "rooms.set_default",
        channel_id: id,
        agent_id: agentId,
      });
      await loadChannel();
    } finally {
      setSettingDefault(null);
    }
  };

  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  const handleDeleteChannel = async () => {
    setIsDeleting(true);
    try {
      await request({
        type: "channels.delete",
        channel_id: id,
      });
      setShowDeleteConfirm(false);
      setSettingsOpen(false);
      navigate("/channels");
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Layout>
      <div className="flex flex-col h-full">
        {/* Header */}
        <header className="h-12 md:h-16 flex-shrink-0 flex items-center px-3 md:px-6 border-b border-border/50 bg-background/80 backdrop-blur-md sticky top-0 z-10">
          <Link
            href="/channels"
            className="mr-4 p-2 -ml-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors"
          >
            <ArrowLeft className="w-5 h-5" />
          </Link>
          {isLoading ? (
            <div className="h-6 w-32 bg-white/5 rounded animate-pulse" />
          ) : (
            <div className="flex items-center gap-2 flex-1 min-w-0">
              <Hash className="w-5 h-5 text-muted-foreground flex-shrink-0" />
              <h2 className="font-bold text-foreground text-lg truncate">
                {channel?.name}
              </h2>
              {channel?.description && (
                <>
                  <span className="text-border/50 mx-2 hidden md:inline">|</span>
                  <span className="text-sm text-muted-foreground font-normal hidden md:inline truncate">
                    {channel.description}
                  </span>
                </>
              )}
            </div>
          )}
          {/* Agent badges */}
          {agents.length > 0 && (
            <div className="flex items-center gap-1 ml-2 flex-shrink-0">
              {agents.map((agent) => (
                <div key={agent.id} title={`${agent.name}${agent.is_active_default ? ' (default)' : ''}`}>
                  <AgentAvatar color={agent.color} name={agent.name} size="sm" />
                </div>
              ))}
            </div>
          )}
          {/* Settings gear icon */}
          <button
            onClick={() => setSettingsOpen(true)}
            className="ml-2 p-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors flex-shrink-0"
            title="Channel settings"
          >
            <Settings className="w-5 h-5" />
          </button>
        </header>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto p-3 md:p-6 scroll-smooth">
          <div className="max-w-4xl mx-auto space-y-1 pb-4">
            {isLoading ? (
              <div className="flex justify-center py-10">
                <Loader2 className="w-6 h-6 animate-spin text-primary" />
              </div>
            ) : messages.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-20 text-center">
                <div className="w-16 h-16 bg-card rounded-2xl flex items-center justify-center mb-4 border border-border/50 shadow-sm">
                  <Hash className="w-8 h-8 text-muted-foreground" />
                </div>
                <h3 className="text-lg font-bold text-foreground mb-1">
                  Welcome to #{channel?.name}!
                </h3>
                <p className="text-muted-foreground">
                  {defaultAgent
                    ? `Send a message — ${defaultAgent.name} is listening.`
                    : "This is the start of the channel."}
                </p>
                {agents.length > 1 && (
                  <p className="text-xs text-muted-foreground mt-2">
                    Use @{agents.map((a) => a.slug).join(", @")} to talk to a specific agent.
                  </p>
                )}
              </div>
            ) : (
              messages.map((msg, index) => {
                const isConsecutive =
                  index > 0 && messages[index - 1].author === msg.author;
                const agent = getAgentForMessage(msg);

                return (
                  <div
                    key={msg.id}
                    className={`flex gap-4 group ${isConsecutive ? "mt-1" : "mt-6"}`}
                  >
                    {!isConsecutive ? (
                      agent ? (
                        <AgentAvatar color={agent.color} name={agent.name} size="lg" className="mt-0.5" />
                      ) : (
                        <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300">
                          {msg.author.charAt(0).toUpperCase()}
                        </div>
                      )
                    ) : (
                      <div className="w-10 flex-shrink-0 flex items-center justify-center opacity-0 group-hover:opacity-100">
                        <span className="text-[10px] text-muted-foreground/50">
                          {msg.created_at ? format(new Date(msg.created_at * 1000), "HH:mm") : ""}
                        </span>
                      </div>
                    )}

                    <div className="flex-1">
                      {!isConsecutive && (
                        <div className="flex items-baseline gap-2 mb-1">
                          <span className={`font-semibold ${agent ? "text-foreground" : "text-foreground"}`}>
                            {msg.author}
                          </span>
                          {agent && (
                            <span className="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-muted-foreground">
                              agent
                            </span>
                          )}
                          <span className="text-xs text-muted-foreground">
                            {msg.created_at ? format(new Date(msg.created_at * 1000), "MMM d, HH:mm") : ""}
                          </span>
                        </div>
                      )}
                      <div className="text-[15px] leading-relaxed text-slate-300">
                        <MarkdownRenderer content={msg.content} />
                      </div>
                    </div>
                  </div>
                );
              })
            )}
            <div ref={messagesEndRef} />
          </div>
        </div>

        {/* Input */}
        <div className="flex-shrink-0 p-2 md:p-4 mb-14 md:mb-0 bg-background border-t border-border/50">
          <div className="max-w-4xl mx-auto relative flex items-end shadow-lg shadow-black/5 rounded-2xl bg-card border border-border/50 focus-within:border-primary/50 focus-within:ring-1 focus-within:ring-primary/20 transition-all">
            <Textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder={
                defaultAgent
                  ? `Message #${channel?.name || "channel"} — ${defaultAgent.name} will respond`
                  : `Message #${channel?.name || "channel"}`
              }
              className="min-h-[60px] max-h-48 w-full resize-none border-0 focus-visible:ring-0 bg-transparent py-4 px-5 text-[15px]"
              rows={1}
            />
            <div className="p-3 flex-shrink-0">
              <Button
                size="icon"
                className="w-10 h-10 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground shadow-md transition-transform active:scale-95 disabled:opacity-50"
                onClick={handleSend}
                disabled={!content.trim() || isPending}
              >
                {isPending ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <Send className="w-4 h-4 ml-0.5" />
                )}
              </Button>
            </div>
          </div>
          {agents.length > 1 && (
            <div className="hidden md:block max-w-4xl mx-auto text-center mt-2 text-[11px] text-muted-foreground/60">
              Use @{agents.map((a) => a.slug).join(", @")} to talk to a specific agent
            </div>
          )}
        </div>
      </div>

      {/* Settings Dialog */}
      <Dialog open={settingsOpen} onOpenChange={setSettingsOpen}>
        <DialogContent className="sm:max-w-lg max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Settings className="w-5 h-5 text-muted-foreground" />
              Channel Settings
            </DialogTitle>
          </DialogHeader>

          <div className="space-y-6 pt-2">
            {/* Channel Name */}
            <div>
              <label className="text-sm font-medium mb-1.5 block text-foreground">
                Channel Name
              </label>
              <div className="flex gap-2">
                <Input
                  value={editName}
                  onChange={(e) => setEditName(e.target.value)}
                  placeholder="Channel name"
                  className="bg-background/50 border-border/50 rounded-xl flex-1"
                />
                <Button
                  size="sm"
                  className="rounded-xl"
                  onClick={handleUpdateName}
                  disabled={isSavingName || !editName.trim() || editName === channel?.name}
                >
                  {isSavingName ? <Loader2 className="w-4 h-4 animate-spin" /> : "Save"}
                </Button>
              </div>
            </div>

            {/* Description */}
            <div>
              <label className="text-sm font-medium mb-1.5 block text-foreground">
                Description
              </label>
              <div className="flex gap-2">
                <Input
                  value={editDescription}
                  onChange={(e) => setEditDescription(e.target.value)}
                  placeholder="Channel description (optional)"
                  className="bg-background/50 border-border/50 rounded-xl flex-1"
                />
                <Button
                  size="sm"
                  className="rounded-xl"
                  onClick={handleUpdateDescription}
                  disabled={isSavingDescription || editDescription === (channel?.description || "")}
                >
                  {isSavingDescription ? <Loader2 className="w-4 h-4 animate-spin" /> : "Save"}
                </Button>
              </div>
            </div>

            {/* Agents */}
            <div>
              <label className="text-sm font-medium mb-1.5 block text-foreground">
                Agents
              </label>
              <p className="text-xs text-muted-foreground mb-3">
                Toggle agents in this channel. Click the crown to set the default responder.
              </p>
              <div className="flex flex-wrap gap-2">
                {allAgents.map((agent) => {
                  const isInChannel = channelAgentIds.has(agent.id);
                  const isDefault = agents.find((a) => a.id === agent.id)?.is_active_default;
                  const isToggling = togglingAgent === agent.id;
                  const isSettingDef = settingDefault === agent.id;

                  return (
                    <div key={agent.id} className="flex items-center gap-0.5">
                      <button
                        type="button"
                        onClick={() => handleToggleAgent(agent.id)}
                        disabled={isToggling}
                        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                          isInChannel
                            ? "border-primary/50 bg-primary/10 text-primary"
                            : "border-border/50 text-muted-foreground hover:border-border"
                        }`}
                      >
                        {isToggling ? (
                          <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <AgentAvatar color={agent.color} name={agent.name} size="sm" />
                        )}
                        {agent.name}
                      </button>
                      {isInChannel && (
                        <button
                          type="button"
                          onClick={() => handleSetDefault(agent.id)}
                          disabled={isSettingDef || isDefault}
                          title={isDefault ? `${agent.name} is the default` : `Set ${agent.name} as default`}
                          className={`p-1 rounded transition-colors ${
                            isDefault
                              ? "text-amber-400"
                              : "text-muted-foreground/40 hover:text-amber-400/70"
                          }`}
                        >
                          {isSettingDef ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <Crown className="w-3.5 h-3.5" />
                          )}
                        </button>
                      )}
                    </div>
                  );
                })}
                {allAgents.length === 0 && (
                  <p className="text-xs text-muted-foreground">No agents available.</p>
                )}
              </div>
            </div>

            {/* Danger Zone */}
            <div className="pt-4 border-t border-border/50">
              <label className="text-sm font-medium mb-1.5 block text-red-400">
                Danger Zone
              </label>
              <p className="text-xs text-muted-foreground mb-3">
                Permanently delete this channel and all its messages.
              </p>
              <Button
                variant="outline"
                className="rounded-xl text-red-400 border-red-400/30 hover:bg-red-400/10 hover:text-red-400 w-full"
                onClick={() => setShowDeleteConfirm(true)}
              >
                <Trash2 className="w-4 h-4 mr-2" />
                Delete Channel
              </Button>

              <Dialog open={showDeleteConfirm} onOpenChange={setShowDeleteConfirm}>
                <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
                  <DialogHeader>
                    <DialogTitle>Delete Channel</DialogTitle>
                  </DialogHeader>
                  <p className="text-sm text-muted-foreground py-2">
                    Are you sure you want to delete this channel and all its messages? This action cannot be undone.
                  </p>
                  <DialogFooter>
                    <Button variant="ghost" className="rounded-xl" onClick={() => setShowDeleteConfirm(false)} disabled={isDeleting}>
                      Cancel
                    </Button>
                    <Button className="rounded-xl bg-red-500 hover:bg-red-600 text-white" onClick={handleDeleteChannel} disabled={isDeleting}>
                      {isDeleting ? "Deleting..." : "Delete"}
                    </Button>
                  </DialogFooter>
                </DialogContent>
              </Dialog>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
