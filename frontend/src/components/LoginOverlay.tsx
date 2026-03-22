import { useState, useEffect } from "react";
import { useWs } from "@/hooks/useWebSocket";
import { Bot, Loader2, Lock } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

export function LoginOverlay() {
  const { status, authenticate, subscribe } = useWs();
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");

  // Listen for auth errors
  useEffect(() => {
    const unsub = subscribe("auth.error", (msg) => {
      setError((msg.error as string) || "Invalid password");
    });
    return unsub;
  }, [subscribe]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!password.trim()) return;
    setError("");
    authenticate(password);
  };

  if (status === "authenticated") return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-background">
      <div className="w-full max-w-sm mx-4 p-8 rounded-2xl bg-card border border-border/50 shadow-2xl">
        <div className="flex flex-col items-center mb-8">
          <div className="w-16 h-16 rounded-2xl bg-gradient-to-br from-primary to-indigo-600 flex items-center justify-center shadow-lg shadow-primary/30 mb-4">
            <Bot className="w-9 h-9 text-white" />
          </div>
          <h1 className="text-2xl font-display font-bold text-foreground">
            Claude Connect
          </h1>
          <p className="text-muted-foreground text-sm mt-1">
            Enter your password to continue
          </p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="relative">
            <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              type="password"
              placeholder="Password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoFocus
              className="pl-9 h-12 bg-background/50 border-border/50 rounded-xl focus-visible:ring-primary/30"
            />
          </div>

          {error && (
            <p className="text-sm text-red-400 text-center">{error}</p>
          )}

          <Button
            type="submit"
            disabled={
              !password.trim() ||
              status === "connecting" ||
              status === "disconnected"
            }
            className="w-full h-12 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20"
          >
            {status === "connecting" || status === "disconnected" ? (
              <>
                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                Connecting...
              </>
            ) : (
              "Sign In"
            )}
          </Button>
        </form>
      </div>
    </div>
  );
}
