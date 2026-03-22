import { useState, useEffect, useCallback } from "react";
import { Bell } from "lucide-react";
import { useWs, type WsMessage } from "@/hooks/useWebSocket";
import { useLocation } from "wouter";
import { cn } from "@/lib/utils";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

interface Notification {
  id: string;
  taskId: string;
  conversationId: string;
  state: "completed" | "failed";
  promptPreview: string;
  resultPreview?: string;
  error?: string;
  costUsd: number;
  timestamp: number;
  read: boolean;
}

export function NotificationBell() {
  const { subscribe } = useWs();
  const [, setLocation] = useLocation();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [isOpen, setIsOpen] = useState(false);

  const unreadCount = notifications.filter((n) => !n.read).length;

  const handleTaskComplete = useCallback((msg: WsMessage) => {
    const state = msg.state as string;
    if (state !== "completed" && state !== "failed") return;

    const notification: Notification = {
      id: `notif_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
      taskId: msg.task_id as string,
      conversationId: (msg.conversation_id as string) || "",
      state: state as "completed" | "failed",
      promptPreview: (msg.prompt_preview as string) || "Background task",
      resultPreview: msg.result_preview as string | undefined,
      error: msg.error as string | undefined,
      costUsd: (msg.cost_usd as number) || 0,
      timestamp: Date.now(),
      read: false,
    };

    setNotifications((prev) => {
      if (prev.some((n) => n.taskId === notification.taskId)) return prev;
      return [notification, ...prev].slice(0, 50);
    });
  }, []);

  useEffect(() => {
    const unsub = subscribe("task.state_changed", handleTaskComplete);
    return () => unsub();
  }, [subscribe, handleTaskComplete]);

  const markAllRead = () => {
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
  };

  const handleNotificationClick = (notif: Notification) => {
    setNotifications((prev) =>
      prev.map((n) => (n.id === notif.id ? { ...n, read: true } : n))
    );
    setIsOpen(false);

    if (notif.conversationId) {
      setLocation(`/conversations/${notif.conversationId}`);
    } else {
      setLocation("/tasks");
    }
  };

  const clearAll = () => {
    setNotifications([]);
    setIsOpen(false);
  };

  const formatTime = (ts: number) => {
    const seconds = Math.floor((Date.now() - ts) / 1000);
    if (seconds < 60) return "just now";
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    return `${hours}h ago`;
  };

  return (
    <>
      <button
        onClick={() => {
          setIsOpen(true);
          if (unreadCount > 0) markAllRead();
        }}
        className={cn(
          "relative p-2 rounded-xl transition-all duration-200",
          unreadCount > 0
            ? "text-primary hover:bg-primary/10"
            : "text-muted-foreground hover:text-foreground hover:bg-white/5"
        )}
      >
        <Bell className="w-5 h-5" />
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -right-0.5 w-5 h-5 bg-primary text-white text-[10px] font-bold rounded-full flex items-center justify-center animate-in zoom-in-50 duration-200">
            {unreadCount > 9 ? "9+" : unreadCount}
          </span>
        )}
      </button>

      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl p-0 gap-0">
          <DialogHeader className="px-4 pt-4 pb-3 border-b border-border/50">
            <div className="flex items-center justify-between">
              <DialogTitle className="text-sm font-bold">
                Notifications
              </DialogTitle>
              {notifications.length > 0 && (
                <button
                  onClick={clearAll}
                  className="text-xs text-muted-foreground hover:text-foreground transition-colors mr-6"
                >
                  Clear all
                </button>
              )}
            </div>
          </DialogHeader>

          <div className="max-h-[60vh] overflow-y-auto">
            {notifications.length === 0 ? (
              <div className="py-12 text-center text-muted-foreground text-sm">
                No notifications yet
              </div>
            ) : (
              notifications.map((notif) => (
                <button
                  key={notif.id}
                  onClick={() => handleNotificationClick(notif)}
                  className={cn(
                    "w-full text-left px-4 py-3 border-b border-border/30 hover:bg-white/5 transition-colors",
                    !notif.read && "bg-primary/5"
                  )}
                >
                  <div className="flex items-start gap-3">
                    <span className="mt-0.5 text-base flex-shrink-0">
                      {notif.state === "completed" ? "\u2705" : "\u274C"}
                    </span>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-foreground truncate">
                        {notif.promptPreview}
                      </p>
                      {notif.state === "completed" && notif.resultPreview && (
                        <p className="text-xs text-muted-foreground mt-0.5 line-clamp-2">
                          {notif.resultPreview}
                        </p>
                      )}
                      {notif.state === "failed" && notif.error && (
                        <p className="text-xs text-red-400 mt-0.5 line-clamp-2">
                          {notif.error}
                        </p>
                      )}
                      <div className="flex items-center gap-2 mt-1 text-[10px] text-muted-foreground">
                        <span>{formatTime(notif.timestamp)}</span>
                        {notif.costUsd > 0 && (
                          <span>${notif.costUsd.toFixed(4)}</span>
                        )}
                        {notif.conversationId && (
                          <span className="text-primary">
                            View conversation
                          </span>
                        )}
                      </div>
                    </div>
                    {!notif.read && (
                      <div className="w-2 h-2 rounded-full bg-primary flex-shrink-0 mt-1.5" />
                    )}
                  </div>
                </button>
              ))
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
