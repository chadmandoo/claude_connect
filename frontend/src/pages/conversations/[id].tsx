import { useState, useRef, useEffect, useCallback } from "react";

import { useParams, Link, useSearch } from "wouter";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
  ArrowLeft,
  Send,
  Bot,
  User,
  Loader2,
  ImagePlus,
  X,
  Info,
  Copy,
  Check,
  Pencil,
} from "lucide-react";
import { useWs, type WsMessage } from "@/hooks/useWebSocket";
import { MarkdownRenderer } from "@/components/MarkdownRenderer";
import { AgentAvatar } from "@/components/AgentAvatar";
import { formatDistanceToNow } from "date-fns";

interface Message {
  id: string;
  role: "user" | "assistant";
  content: string;
  created_at?: number;
}

interface PendingImage {
  data: string;
  media_type: string;
  preview: string;
}

interface ConversationMeta {
  id: string;
  title: string;
  type: string;
  project_id: string;
  state: string;
  source: string;
  created_at: number;
  updated_at: number;
}

export default function ConversationDetail() {
  const params = useParams<{ id: string }>();
  const id = params.id;
  const searchString = useSearch();

  const { send, subscribe, request } = useWs();

  const [messages, setMessages] = useState<Message[]>([]);
  const [content, setContent] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isStreaming, setIsStreaming] = useState(false);
  const [streamingContent, setStreamingContent] = useState("");
  const [conversationId, setConversationId] = useState<string | null>(
    id === "new" ? null : id
  );
  const [title, setTitle] = useState("");
  const [pendingImages, setPendingImages] = useState<PendingImage[]>([]);
  const [elapsed, setElapsed] = useState("");
  const [showInfo, setShowInfo] = useState(false);
  const [convMeta, setConvMeta] = useState<ConversationMeta | null>(null);
  const [agentType, setAgentType] = useState("");
  const [projectName, setProjectName] = useState("");
  const [agentInfo, setAgentInfo] = useState<{ name: string; color: string; slug: string } | null>(null);
  const [copiedField, setCopiedField] = useState("");
  const [agentId] = useState<string>(() => {
    const params = new URLSearchParams(searchString);
    return params.get("agent") || "";
  });
  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [isSavingTitle, setIsSavingTitle] = useState(false);

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const conversationIdRef = useRef<string | null>(conversationId);
  const activeTaskIdRef = useRef<string | null>(null);

  // Keep refs in sync with state
  useEffect(() => {
    conversationIdRef.current = conversationId;
  }, [conversationId]);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages, streamingContent]);

  // Client-side timeout: if loading/streaming for > 5 min with no updates, force fail
  useEffect(() => {
    if (!isLoading && !isStreaming) return;
    const timer = setTimeout(() => {
      if (isLoading || isStreaming) {
        setIsStreaming(false);
        setIsLoading(false);
        setStreamingContent("");
        setElapsed("");
        setMessages((prev) => [
          ...prev,
          {
            id: `timeout-${Date.now()}`,
            role: "assistant",
            content: "**Request timed out** — no response received after 5 minutes. The task may still be running in the background. Check the #system channel for updates.",
            created_at: Math.floor(Date.now() / 1000),
          },
        ]);
      }
    }, 5 * 60 * 1000);
    return () => clearTimeout(timer);
  }, [isLoading, isStreaming]);

  // Parse turns from server response into Message[]
  const parseTurns = useCallback(
    (turns: Array<Record<string, unknown>>): Message[] =>
      turns.map((t) => ({
        id: (t.id as string) || (t.task_id as string) || `turn-${Math.random()}`,
        role: (t.role as "user" | "assistant") || "user",
        content: (t.content as string) || "",
        created_at: Number(t.created_at || 0),
      })),
    []
  );

  // Fetch conversation from server
  const fetchConversation = useCallback(
    async (convId: string) => {
      try {
        const resp = await request({
          type: "conversations.get",
          conversation_id: convId,
        });
        if (resp.conversation) {
          const conv = resp.conversation as Record<string, unknown>;
          setTitle((conv.title as string) || (conv.summary as string) || "");
          setConvMeta({
            id: (conv.id as string) || convId,
            title: (conv.title as string) || (conv.summary as string) || "",
            type: (conv.type as string) || "",
            project_id: (conv.project_id as string) || "general",
            state: (conv.state as string) || "",
            source: (conv.source as string) || "",
            created_at: Number(conv.created_at || 0),
            updated_at: Number(conv.updated_at || 0),
          });
          if (conv.agent_name) {
            setAgentInfo({
              name: conv.agent_name as string,
              color: (conv.agent_color as string) || "#6366f1",
              slug: (conv.agent_slug as string) || "",
            });
          }
        }
        const turns =
          (resp.turns as Array<Record<string, unknown>>) || [];
        return parseTurns(turns);
      } catch {
        return null;
      }
    },
    [request, parseTurns]
  );

  // Load existing conversation
  useEffect(() => {
    if (id !== "new" && id) {
      fetchConversation(id).then((msgs) => {
        if (msgs) setMessages(msgs);
      });
    }
  }, [id, fetchConversation]);

  // Poll for new messages every 5s (catches results even if WS missed them)
  useEffect(() => {
    const convId = conversationId || (id !== "new" ? id : null);
    if (!convId) return;

    const interval = setInterval(async () => {
      const serverMsgs = await fetchConversation(convId);
      if (serverMsgs && serverMsgs.length > messages.length) {
        // Server has more messages — merge, keeping any local optimistic messages
        setMessages(serverMsgs);
        // If we were loading/streaming, the result arrived via polling
        if (isLoading || isStreaming) {
          setIsLoading(false);
          setIsStreaming(false);
          setStreamingContent("");
          setElapsed("");
        }
      }
    }, 5000);

    return () => clearInterval(interval);
  }, [conversationId, id, fetchConversation, messages.length, isLoading, isStreaming]);

  // Subscribe to chat events
  useEffect(() => {
    const unsubs: (() => void)[] = [];

    unsubs.push(
      subscribe("chat.ack", (msg) => {
        const ackConvId = msg.conversation_id as string;
        // For existing conversations, only accept acks for this conversation
        if (conversationIdRef.current && ackConvId && ackConvId !== conversationIdRef.current) {
          return;
        }
        if (ackConvId) {
          setConversationId(ackConvId);
        }
        if (msg.task_id) {
          activeTaskIdRef.current = msg.task_id as string;
        }
        if (msg.agent_type) {
          setAgentType(msg.agent_type as string);
        }
        if (msg.project_name) {
          setProjectName(msg.project_name as string);
        }
        if (msg.agent && typeof msg.agent === "object") {
          const a = msg.agent as Record<string, string>;
          setAgentInfo({
            name: a.name || "",
            color: a.color || "#6366f1",
            slug: a.slug || "",
          });
        }
        setIsStreaming(true);
        setStreamingContent("");
      })
    );

    unsubs.push(
      subscribe("chat.progress", (msg) => {
        // Only accept progress for our active task
        if (activeTaskIdRef.current && msg.task_id && msg.task_id !== activeTaskIdRef.current) {
          return;
        }
        if (msg.stderr_line) {
          // Could show progress indicator
        }
        if (msg.elapsed) {
          setElapsed(msg.elapsed as string);
        }
      })
    );

    unsubs.push(
      subscribe("chat.result", (msg) => {
        // Only accept results for this conversation
        const resultConvId = msg.conversation_id as string;
        if (resultConvId && conversationIdRef.current && resultConvId !== conversationIdRef.current) {
          return;
        }
        setIsStreaming(false);
        setIsLoading(false);
        setStreamingContent("");
        setElapsed("");
        activeTaskIdRef.current = null;
        const result = (msg.result as string) || "";
        if (result) {
          setMessages((prev) => [
            ...prev,
            {
              id: `assistant-${Date.now()}`,
              role: "assistant",
              content: result,
              created_at: Math.floor(Date.now() / 1000),
            },
          ]);
        }
      })
    );

    unsubs.push(
      subscribe("chat.error", (msg) => {
        // Only accept errors for our active task
        if (activeTaskIdRef.current && msg.task_id && msg.task_id !== activeTaskIdRef.current) {
          return;
        }
        setIsStreaming(false);
        setIsLoading(false);
        setStreamingContent("");
        setElapsed("");
        activeTaskIdRef.current = null;
        setMessages((prev) => [
          ...prev,
          {
            id: `error-${Date.now()}`,
            role: "assistant",
            content: `Error: ${msg.error || "Unknown error"}`,
            created_at: Math.floor(Date.now() / 1000),
          },
        ]);
      })
    );

    // Listen for background task state changes — only for this conversation
    unsubs.push(
      subscribe("task.state_changed", (msg) => {
        const msgConvId = msg.conversation_id as string;
        // Only show inline if this task belongs to the current conversation
        if (!msgConvId || !conversationIdRef.current || msgConvId !== conversationIdRef.current) {
          return;
        }

        const state = msg.state as string;
        const taskId = (msg.task_id as string) || "";
        const shortId = taskId.substring(0, 8);

        if (state === "completed") {
          const preview = (msg.result_preview as string) || "Task completed.";
          const cost = msg.cost_usd ? ` ($${Number(msg.cost_usd).toFixed(4)})` : "";
          setMessages((prev) => [
            ...prev,
            {
              id: `task-done-${Date.now()}`,
              role: "assistant",
              content: `**Task \`${shortId}\` completed**${cost}\n\n${preview}`,
              created_at: Math.floor(Date.now() / 1000),
            },
          ]);
        } else if (state === "failed") {
          setIsStreaming(false);
          setIsLoading(false);
          setStreamingContent("");
          setElapsed("");
          activeTaskIdRef.current = null;
          const error = (msg.error as string) || "Unknown error";
          setMessages((prev) => [
            ...prev,
            {
              id: `task-fail-${Date.now()}`,
              role: "assistant",
              content: `**Task \`${shortId}\` failed**\n\n${error}`,
              created_at: Math.floor(Date.now() / 1000),
            },
          ]);
        }
      })
    );

    return () => unsubs.forEach((u) => u());
  }, [subscribe]);

  const handleSend = () => {
    if (!content.trim() || isLoading) return;

    const userMsg: Message = {
      id: `user-${Date.now()}`,
      role: "user",
      content: content.trim(),
      created_at: Math.floor(Date.now() / 1000),
    };

    setMessages((prev) => [...prev, userMsg]);
    setIsLoading(true);

    send({
      type: "chat.send",
      prompt: content.trim(),
      conversation_id: conversationId,
      agent_id: agentId || undefined,
      images: pendingImages.map((img) => ({
        data: img.data,
        media_type: img.media_type,
      })),
    });

    setContent("");
    setPendingImages([]);
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard.writeText(text);
    setCopiedField(field);
    setTimeout(() => setCopiedField(""), 1500);
  };

  const handleSaveTitle = async () => {
    const newTitle = editTitle.trim();
    if (!newTitle || !conversationId) return;
    setIsSavingTitle(true);
    try {
      await request({
        type: "conversations.update",
        conversation_id: conversationId,
        title: newTitle,
      });
      setTitle(newTitle);
      if (convMeta) {
        setConvMeta({ ...convMeta, title: newTitle });
      }
      setIsEditingTitle(false);
    } finally {
      setIsSavingTitle(false);
    }
  };

  const handleImageUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files) return;

    Array.from(files).forEach((file) => {
      if (file.size > 5 * 1024 * 1024) return; // 5MB limit
      const reader = new FileReader();
      reader.onload = () => {
        const base64 = (reader.result as string).split(",")[1];
        setPendingImages((prev) => [
          ...prev,
          {
            data: base64,
            media_type: file.type,
            preview: reader.result as string,
          },
        ]);
      };
      reader.readAsDataURL(file);
    });
    e.target.value = "";
  };

  return (
    <Layout>
      <div className="flex flex-col h-full">
        {/* Header */}
        <header className="h-12 md:h-16 flex-shrink-0 flex items-center justify-between px-3 md:px-6 border-b border-border/50 bg-background/80 backdrop-blur-md sticky top-0 z-10">
          <div className="flex items-center min-w-0">
            <Link
              href="/conversations"
              className="mr-4 p-2 -ml-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors flex-shrink-0"
            >
              <ArrowLeft className="w-5 h-5" />
            </Link>
            <div className="min-w-0">
              <h2 className="font-bold text-foreground flex items-center gap-2 truncate">
                {title || (id === "new" ? "New Conversation" : `Chat`)}
              </h2>
              {isStreaming && elapsed && (
                <span className="text-xs text-muted-foreground">
                  Thinking... {elapsed}
                </span>
              )}
            </div>
          </div>
          {id !== "new" && (
            <button
              onClick={() => setShowInfo(true)}
              className="p-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors flex-shrink-0"
              title="Chat info"
            >
              <Info className="w-5 h-5" />
            </button>
          )}
        </header>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto p-3 md:p-6 scroll-smooth">
          <div className="max-w-3xl mx-auto space-y-4 md:space-y-6 pb-4">
            {messages.length === 0 && !isLoading ? (
              <div className="text-center py-20 text-muted-foreground">
                Send a message to start the conversation.
              </div>
            ) : (
              messages.map((msg) => {
                const isAI = msg.role === "assistant";
                return (
                  <div
                    key={msg.id}
                    className={`flex gap-4 ${isAI ? "" : "flex-row-reverse"}`}
                  >
                    {isAI && agentInfo ? (
                      <AgentAvatar color={agentInfo.color} name={agentInfo.name} size="md" />
                    ) : (
                      <div
                        className={`flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center ${
                          isAI
                            ? "bg-primary/20 text-primary"
                            : "bg-slate-800 text-slate-300"
                        }`}
                      >
                        {isAI ? (
                          <Bot className="w-5 h-5" />
                        ) : (
                          <User className="w-5 h-5" />
                        )}
                      </div>
                    )}
                    <div
                      className={`flex flex-col ${
                        isAI ? "items-start" : "items-end"
                      } max-w-[80%]`}
                    >
                      <div className="flex items-center gap-2 mb-1 px-1">
                        <span className="text-xs font-medium text-muted-foreground">
                          {isAI ? (agentInfo?.name || "Claude") : "You"}
                        </span>
                      </div>
                      <div
                        className={`px-5 py-3 rounded-2xl text-[15px] leading-relaxed shadow-sm ${
                          isAI
                            ? "bg-card border border-border/50 text-foreground rounded-tl-sm"
                            : "bg-primary text-primary-foreground rounded-tr-sm"
                        }`}
                      >
                        {isAI ? (
                          <MarkdownRenderer content={msg.content} />
                        ) : (
                          msg.content
                        )}
                      </div>
                    </div>
                  </div>
                );
              })
            )}

            {isStreaming && (
              <div className="flex gap-4">
                {agentInfo ? (
                  <AgentAvatar color={agentInfo.color} name={agentInfo.name} size="md" />
                ) : (
                  <div className="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center bg-primary/20 text-primary">
                    <Bot className="w-5 h-5" />
                  </div>
                )}
                <div className="flex flex-col items-start max-w-[80%]">
                  <div className="flex items-center gap-2 mb-1 px-1">
                    <span className="text-xs font-medium text-muted-foreground">
                      {agentInfo?.name || "Claude"}
                    </span>
                  </div>
                  <div className="px-5 py-3 rounded-2xl rounded-tl-sm bg-card border border-border/50 text-foreground">
                    <div className="flex items-center gap-2">
                      <Loader2 className="w-4 h-4 animate-spin text-primary" />
                      <span className="text-sm text-muted-foreground">
                        Thinking...
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            )}

            <div ref={messagesEndRef} />
          </div>
        </div>

        {/* Image Previews */}
        {pendingImages.length > 0 && (
          <div className="px-6 py-2 border-t border-border/50 bg-background/50">
            <div className="max-w-3xl mx-auto flex gap-2 overflow-x-auto">
              {pendingImages.map((img, i) => (
                <div key={i} className="relative flex-shrink-0">
                  <img
                    src={img.preview}
                    alt=""
                    className="h-16 w-16 rounded-lg object-cover border border-border/50"
                  />
                  <button
                    onClick={() =>
                      setPendingImages((prev) =>
                        prev.filter((_, idx) => idx !== i)
                      )
                    }
                    className="absolute -top-1 -right-1 w-5 h-5 bg-red-500 rounded-full flex items-center justify-center"
                  >
                    <X className="w-3 h-3 text-white" />
                  </button>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Input */}
        <div className="flex-shrink-0 p-2 md:p-4 pb-[calc(0.5rem+env(safe-area-inset-bottom,0px))] md:pb-4 mb-14 md:mb-0 bg-background border-t border-border/50">
          <div className="max-w-3xl mx-auto relative flex items-end shadow-lg shadow-black/5 rounded-2xl bg-card border border-border/50 focus-within:border-primary/50 focus-within:ring-1 focus-within:ring-primary/20 transition-all">
            <button
              onClick={() => fileInputRef.current?.click()}
              className="p-2 md:p-3 text-muted-foreground hover:text-foreground transition-colors flex-shrink-0"
            >
              <ImagePlus className="w-5 h-5" />
            </button>
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*"
              multiple
              className="hidden"
              onChange={handleImageUpload}
            />
            <Textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Type your message..."
              className="min-h-[60px] max-h-48 w-full resize-none border-0 focus-visible:ring-0 bg-transparent py-4 px-2 text-[15px]"
              rows={1}
            />
            <div className="p-3 flex-shrink-0">
              <Button
                size="icon"
                className="w-10 h-10 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground shadow-md transition-transform active:scale-95 disabled:opacity-50"
                onClick={handleSend}
                disabled={!content.trim() || isLoading}
              >
                {isLoading ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <Send className="w-4 h-4 ml-0.5" />
                )}
              </Button>
            </div>
          </div>
          <div className="hidden md:block max-w-3xl mx-auto text-center mt-2 text-[11px] text-muted-foreground/60">
            Press Enter to send, Shift + Enter for new line.
          </div>
        </div>
      </div>

      {/* Chat Info Dialog */}
      <Dialog open={showInfo} onOpenChange={setShowInfo}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Chat Info</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            {/* Chat ID */}
            <div>
              <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Chat ID
              </label>
              <div className="flex items-center gap-2 mt-1">
                <code className="text-sm font-mono bg-background/50 px-2 py-1 rounded border border-border/50 flex-1 truncate">
                  {conversationId || id}
                </code>
                <button
                  onClick={() => copyToClipboard(conversationId || id || "", "id")}
                  className="p-1.5 rounded hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors flex-shrink-0"
                >
                  {copiedField === "id" ? (
                    <Check className="w-4 h-4 text-green-400" />
                  ) : (
                    <Copy className="w-4 h-4" />
                  )}
                </button>
              </div>
            </div>

            {/* Title */}
            <div>
              <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Title
              </label>
              {isEditingTitle ? (
                <div className="flex items-center gap-2 mt-1">
                  <Input
                    value={editTitle}
                    onChange={(e) => setEditTitle(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") handleSaveTitle();
                      if (e.key === "Escape") setIsEditingTitle(false);
                    }}
                    className="bg-background/50 border-border/50 rounded-lg text-sm h-8"
                    autoFocus
                  />
                  <Button
                    size="sm"
                    className="h-8 px-3 rounded-lg"
                    onClick={handleSaveTitle}
                    disabled={isSavingTitle || !editTitle.trim()}
                  >
                    {isSavingTitle ? (
                      <Loader2 className="w-3 h-3 animate-spin" />
                    ) : (
                      "Save"
                    )}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-8 px-2 rounded-lg"
                    onClick={() => setIsEditingTitle(false)}
                  >
                    <X className="w-3 h-3" />
                  </Button>
                </div>
              ) : (
                <div className="flex items-center gap-2 mt-1 group/title">
                  <p className="text-sm text-foreground">
                    {title || convMeta?.title || "Untitled"}
                  </p>
                  <button
                    onClick={() => {
                      setEditTitle(title || convMeta?.title || "");
                      setIsEditingTitle(true);
                    }}
                    className="p-1 rounded hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors opacity-0 group-hover/title:opacity-100"
                  >
                    <Pencil className="w-3 h-3" />
                  </button>
                </div>
              )}
            </div>

            {/* Type & Agent */}
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Type
                </label>
                <p className="text-sm text-foreground mt-1 capitalize">
                  {convMeta?.type || "task"}
                </p>
              </div>
              <div>
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Agent
                </label>
                <div className="flex items-center gap-2 mt-1">
                  {agentInfo ? (
                    <>
                      <AgentAvatar color={agentInfo.color} name={agentInfo.name} size="sm" />
                      <span className="text-sm text-foreground">{agentInfo.name}</span>
                    </>
                  ) : (
                    <p className="text-sm text-foreground capitalize">{agentType || "pm"}</p>
                  )}
                </div>
              </div>
            </div>

            {/* Project & State */}
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Project
                </label>
                <p className="text-sm text-foreground mt-1">
                  {projectName || convMeta?.project_id || "General"}
                </p>
              </div>
              <div>
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  State
                </label>
                <p className="text-sm text-foreground mt-1 capitalize">
                  {convMeta?.state || "active"}
                </p>
              </div>
            </div>

            {/* Messages & Source */}
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Messages
                </label>
                <p className="text-sm text-foreground mt-1">{messages.length}</p>
              </div>
              <div>
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Source
                </label>
                <p className="text-sm text-foreground mt-1 capitalize">
                  {convMeta?.source || "web"}
                </p>
              </div>
            </div>

            {/* Created */}
            {convMeta?.created_at ? (
              <div>
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Created
                </label>
                <p className="text-sm text-foreground mt-1">
                  {formatDistanceToNow(new Date(convMeta.created_at * 1000), {
                    addSuffix: true,
                  })}
                </p>
              </div>
            ) : null}
          </div>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
