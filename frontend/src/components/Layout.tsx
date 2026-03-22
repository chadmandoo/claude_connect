import { type ReactNode, useState, useEffect } from "react";
import { Link, useLocation } from "wouter";
import {
  MessageSquare,
  Hash,
  SquareKanban,
  BrainCircuit,
  Clock,
  ListChecks,
  CheckSquare,
  Bot,
  BookOpen,
  StickyNote,
  Wifi,
  WifiOff,
  Menu,
  X,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useWs } from "@/hooks/useWebSocket";
import { NotificationBell } from "@/components/NotificationBell";

const navItems = [
  { name: "Notes", href: "/notes", icon: StickyNote },
  { name: "Todos", href: "/todos", icon: CheckSquare },
  { name: "Conversations", href: "/conversations", icon: MessageSquare },
  { name: "Rooms", href: "/channels", icon: Hash },
  { name: "Projects", href: "/projects", icon: SquareKanban },
  { name: "Tasks", href: "/tasks", icon: ListChecks },
  { name: "Memory", href: "/memory", icon: BrainCircuit },
  { name: "Scheduler", href: "/skills", icon: Clock },
  { name: "Agents", href: "/agents", icon: Bot },
  { name: "System", href: "/system", icon: BookOpen },
];

export function Layout({ children }: { children: ReactNode }) {
  const [location] = useLocation();
  const { status, userId } = useWs();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  // Close sidebar on route change
  useEffect(() => {
    setSidebarOpen(false);
  }, [location]);

  return (
    <div className="flex h-[100dvh] overflow-hidden bg-background">
      {/* Mobile overlay */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/60 md:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <nav
        className={cn(
          "fixed md:relative z-40 h-full w-64 flex-shrink-0 border-r border-border/50 bg-background/95 backdrop-blur-xl flex flex-col transition-transform duration-300 ease-in-out",
          sidebarOpen ? "translate-x-0" : "-translate-x-full md:translate-x-0"
        )}
      >
        <div className="h-14 md:h-16 flex items-center justify-between px-4 md:px-6 border-b border-border/50">
          <Link href="/" className="flex items-center gap-3 group">
            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-primary to-indigo-600 flex items-center justify-center shadow-lg shadow-primary/20 group-hover:shadow-primary/40 transition-all">
              <Bot className="w-5 h-5 text-white" />
            </div>
            <span className="font-display font-bold text-lg tracking-tight text-foreground">
              Claude Connect
            </span>
          </Link>
          <div className="flex items-center gap-1">
            <NotificationBell />
            <button
              className="md:hidden p-1.5 rounded-lg hover:bg-white/5 text-muted-foreground"
              onClick={() => setSidebarOpen(false)}
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        <div className="flex-1 py-4 md:py-6 px-3 space-y-1 overflow-y-auto">
          <div className="px-3 mb-2 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
            Workspace
          </div>
          {navItems.map((item) => {
            const isActive = location.startsWith(item.href);
            return (
              <Link
                key={item.name}
                href={item.href}
                className={cn(
                  "flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 group relative",
                  isActive
                    ? "text-primary bg-primary/10"
                    : "text-muted-foreground hover:text-foreground hover:bg-white/5"
                )}
              >
                {isActive && (
                  <div className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-5 bg-primary rounded-r-full" />
                )}
                <item.icon
                  className={cn(
                    "w-4 h-4",
                    isActive
                      ? "text-primary"
                      : "text-muted-foreground group-hover:text-foreground"
                  )}
                />
                {item.name}
              </Link>
            );
          })}
        </div>

        <div className="p-4 border-t border-border/50">
          <div className="flex items-center gap-3 px-2 py-2">
            <div className="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center">
              <span className="text-xs font-medium">
                {userId ? userId.charAt(0).toUpperCase() : "?"}
              </span>
            </div>
            <div className="flex flex-col flex-1">
              <span className="text-sm font-medium leading-none">
                {userId || "User"}
              </span>
              <span className="text-xs text-muted-foreground mt-1 flex items-center gap-1">
                {status === "authenticated" ? (
                  <>
                    <Wifi className="w-3 h-3 text-emerald-500" /> Connected
                  </>
                ) : (
                  <>
                    <WifiOff className="w-3 h-3 text-red-400" /> {status}
                  </>
                )}
              </span>
            </div>
          </div>
        </div>
      </nav>

      {/* Main Content */}
      <main className="flex-1 relative z-10 flex flex-col min-w-0 overflow-hidden">
        {/* Mobile top bar */}
        <div className="md:hidden h-14 flex items-center justify-between px-4 border-b border-border/50 bg-background/80 backdrop-blur-md flex-shrink-0">
          <button
            className="p-1.5 rounded-lg hover:bg-white/5 text-muted-foreground"
            onClick={() => setSidebarOpen(true)}
          >
            <Menu className="w-5 h-5" />
          </button>
          <span className="font-display font-bold text-sm text-foreground">
            {navItems.find((n) => location.startsWith(n.href))?.name || "Claude Connect"}
          </span>
          <div className="w-8" /> {/* spacer for centering */}
        </div>
        {children}
      </main>

      {/* Mobile bottom tab bar */}
      <div className="md:hidden fixed bottom-0 left-0 right-0 z-20 border-t border-border/50 bg-background/95 backdrop-blur-xl safe-bottom">
        <div className="flex items-center justify-around h-14">
          {navItems.slice(0, 4).map((item) => {
            const isActive = location.startsWith(item.href);
            return (
              <Link
                key={item.name}
                href={item.href}
                className={cn(
                  "flex flex-col items-center gap-0.5 px-3 py-1.5 rounded-lg transition-colors min-w-0",
                  isActive
                    ? "text-primary"
                    : "text-muted-foreground"
                )}
              >
                <item.icon className="w-5 h-5" />
                <span className="text-[10px] font-medium truncate">
                  {item.name}
                </span>
              </Link>
            );
          })}
          <button
            onClick={() => setSidebarOpen(true)}
            className="flex flex-col items-center gap-0.5 px-3 py-1.5 rounded-lg text-muted-foreground"
          >
            <Menu className="w-5 h-5" />
            <span className="text-[10px] font-medium">More</span>
          </button>
        </div>
      </div>
    </div>
  );
}
