import { useState, useEffect, useCallback } from "react";
import { useParams, Link } from "wouter";
import { Layout } from "@/components/Layout";
import { ArrowLeft, BookOpen, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useWs } from "@/hooks/useWebSocket";
import { MarkdownRenderer } from "@/components/MarkdownRenderer";
import { formatDistanceToNow } from "date-fns";

interface Doc {
  slug: string;
  title: string;
  content: string;
  updated_at: number;
}

export default function SystemDocDetail() {
  const { slug } = useParams<{ slug: string }>();
  const { request } = useWs();
  const [doc, setDoc] = useState<Doc | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const loadDoc = useCallback(async () => {
    try {
      const resp = await request({ type: "docs.get", slug });
      if (resp.doc) {
        setDoc(resp.doc as Doc);
      }
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [slug, request]);

  useEffect(() => {
    loadDoc();
  }, [loadDoc]);

  if (isLoading) {
    return (
      <Layout>
        <div className="flex items-center justify-center h-full">
          <Loader2 className="w-8 h-8 text-primary animate-spin" />
        </div>
      </Layout>
    );
  }

  if (!doc) {
    return (
      <Layout>
        <div className="flex flex-col items-center justify-center h-full gap-4 text-muted-foreground">
          <BookOpen className="w-12 h-12" />
          <p>Document not found</p>
          <Link href="/system">
            <Button variant="outline" className="rounded-xl">Back to System</Button>
          </Link>
        </div>
      </Layout>
    );
  }

  return (
    <Layout>
      <div className="flex flex-col h-full">
        {/* Header */}
        <header className="h-12 md:h-16 flex-shrink-0 flex items-center px-3 md:px-6 border-b border-border/50 bg-background/80 backdrop-blur-md sticky top-0 z-10">
          <Link
            href="/system"
            className="mr-4 p-2 -ml-2 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors"
          >
            <ArrowLeft className="w-5 h-5" />
          </Link>
          <div className="min-w-0 flex-1">
            <h2 className="font-bold text-foreground truncate">{doc.title}</h2>
            {doc.updated_at > 0 && (
              <span className="text-xs text-muted-foreground">
                Updated {formatDistanceToNow(new Date(doc.updated_at * 1000), { addSuffix: true })}
              </span>
            )}
          </div>
        </header>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-4 md:p-8">
          <div className="max-w-4xl mx-auto">
            <article className="prose prose-invert prose-sm max-w-none prose-headings:font-display prose-headings:text-foreground prose-p:text-slate-300 prose-li:text-slate-300 prose-a:text-primary prose-strong:text-foreground prose-code:text-primary prose-code:bg-primary/10 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-pre:bg-slate-900 prose-pre:border prose-pre:border-border/50 prose-table:text-sm prose-th:text-foreground prose-td:text-slate-300 prose-th:border-border/50 prose-td:border-border/50">
              <MarkdownRenderer content={doc.content} />
            </article>
          </div>
        </div>
      </div>
    </Layout>
  );
}
