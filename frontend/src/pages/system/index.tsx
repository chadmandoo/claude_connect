import { useState, useEffect, useCallback } from "react";
import { useLocation } from "wouter";
import { Layout } from "@/components/Layout";
import { BookOpen, FileText } from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { formatDistanceToNow } from "date-fns";

interface Doc {
  slug: string;
  filename: string;
  title: string;
  size: number;
  updated_at: number;
}

export default function SystemDocs() {
  const { request } = useWs();
  const [, setLocation] = useLocation();
  const [docs, setDocs] = useState<Doc[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const loadDocs = useCallback(async () => {
    try {
      const resp = await request({ type: "docs.list" });
      setDocs((resp.docs as Doc[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  useEffect(() => {
    loadDocs();
  }, [loadDocs]);

  const formatSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`;
    return `${(bytes / 1024).toFixed(1)} KB`;
  };

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-4xl mx-auto space-y-6 md:space-y-8">
          <div>
            <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
              System Documentation
            </h1>
            <p className="text-muted-foreground mt-1">
              Architecture guides and reference documentation for Claude Connect
            </p>
          </div>

          {isLoading ? (
            <div className="grid gap-3 md:grid-cols-2">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="h-28 rounded-2xl bg-card border border-border/50 animate-pulse" />
              ))}
            </div>
          ) : docs.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-20 text-center">
              <div className="w-16 h-16 bg-card rounded-2xl flex items-center justify-center mb-4 border border-border/50 shadow-sm">
                <BookOpen className="w-8 h-8 text-muted-foreground" />
              </div>
              <h3 className="text-lg font-bold text-foreground mb-1">No documentation yet</h3>
              <p className="text-muted-foreground">Add .md files to the docs/ directory to get started.</p>
            </div>
          ) : (
            <div className="grid gap-3 md:grid-cols-2">
              {docs.map((doc) => (
                <button
                  key={doc.slug}
                  onClick={() => setLocation(`/system/${doc.slug}`)}
                  className="text-left p-5 rounded-2xl bg-card border border-border/50 hover:border-primary/30 hover:shadow-lg hover:shadow-primary/5 transition-all group"
                >
                  <div className="flex items-start gap-4">
                    <div className="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center flex-shrink-0 group-hover:bg-primary/20 transition-colors">
                      <FileText className="w-5 h-5 text-primary" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <h3 className="font-semibold text-foreground group-hover:text-primary transition-colors line-clamp-1">
                        {doc.title}
                      </h3>
                      <div className="flex items-center gap-3 mt-1.5">
                        <span className="text-xs text-muted-foreground">
                          {formatSize(doc.size)}
                        </span>
                        {doc.updated_at > 0 && (
                          <span className="text-xs text-muted-foreground">
                            Updated {formatDistanceToNow(new Date(doc.updated_at * 1000), { addSuffix: true })}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    </Layout>
  );
}
