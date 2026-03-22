import { useState, useEffect, useCallback, useRef, useMemo } from "react";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { DataTable } from "@/components/ui/data-table";
import {
  Plus,
  Loader2,
  Trash2,
  BookOpen,
  FileText,
  Pin,
  PinOff,
  ChevronLeft,
  Pencil,
  Check,
  X,
  ArrowRightLeft,
} from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { formatDistanceToNow } from "date-fns";
import { cn } from "@/lib/utils";
import { type ColumnDef } from "@tanstack/react-table";

interface Notebook {
  id: string;
  title: string;
  description: string;
  color: string;
  icon: string;
  page_count: number;
  sort_order: number;
  created_at: number;
  updated_at: number;
}

interface Page {
  id: string;
  notebook_id: string;
  title: string;
  content: string;
  pinned: string; // '0' or '1' from backend
  sort_order: number;
  created_at: number;
  updated_at: number;
}

const NOTEBOOK_COLORS: { value: string; label: string; bg: string; text: string; border: string }[] = [
  { value: "slate", label: "Slate", bg: "bg-slate-500/10", text: "text-slate-400", border: "border-slate-500/30" },
  { value: "blue", label: "Blue", bg: "bg-blue-500/10", text: "text-blue-400", border: "border-blue-500/30" },
  { value: "purple", label: "Purple", bg: "bg-purple-500/10", text: "text-purple-400", border: "border-purple-500/30" },
  { value: "green", label: "Green", bg: "bg-emerald-500/10", text: "text-emerald-400", border: "border-emerald-500/30" },
  { value: "orange", label: "Orange", bg: "bg-orange-500/10", text: "text-orange-400", border: "border-orange-500/30" },
  { value: "red", label: "Red", bg: "bg-red-500/10", text: "text-red-400", border: "border-red-500/30" },
  { value: "pink", label: "Pink", bg: "bg-pink-500/10", text: "text-pink-400", border: "border-pink-500/30" },
  { value: "amber", label: "Amber", bg: "bg-amber-500/10", text: "text-amber-400", border: "border-amber-500/30" },
];

function getColorClasses(color: string) {
  return NOTEBOOK_COLORS.find((c) => c.value === color) || NOTEBOOK_COLORS[0];
}

export default function NotesPage() {
  const { request, subscribe } = useWs();
  const [notebooks, setNotebooks] = useState<Notebook[]>([]);
  const [pages, setPages] = useState<Page[]>([]);
  const [selectedNotebook, setSelectedNotebook] = useState<string | null>(null);
  const [selectedPage, setSelectedPage] = useState<Page | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isPagesLoading, setIsPagesLoading] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isEditingTitle, setIsEditingTitle] = useState(false);

  // Dialogs
  const [showCreateNotebook, setShowCreateNotebook] = useState(false);
  const [showCreatePage, setShowCreatePage] = useState(false);
  const [deleteNotebook, setDeleteNotebook] = useState<string | null>(null);
  const [deletePage, setDeletePage] = useState<string | null>(null);
  const [editingNotebook, setEditingNotebook] = useState<string | null>(null);
  const [editNotebookTitle, setEditNotebookTitle] = useState("");

  // Form state
  const [newNotebookTitle, setNewNotebookTitle] = useState("");
  const [newNotebookDesc, setNewNotebookDesc] = useState("");
  const [newNotebookColor, setNewNotebookColor] = useState("blue");
  const [newPageTitle, setNewPageTitle] = useState("");
  const [isCreating, setIsCreating] = useState(false);

  // Editor state
  const [editTitle, setEditTitle] = useState("");
  const [editContent, setEditContent] = useState("");
  const [isSaving, setIsSaving] = useState(false);
  const saveTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [lastSaved, setLastSaved] = useState<Date | null>(null);

  // Move page dialog
  const [movePageId, setMovePageId] = useState<string | null>(null);

  // Load notebooks
  const loadNotebooks = useCallback(async () => {
    try {
      const resp = await request({ type: "notebooks.list" });
      setNotebooks((resp.notebooks as Notebook[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  // Load pages for selected notebook
  const loadPages = useCallback(
    async (notebookId: string) => {
      setIsPagesLoading(true);
      try {
        const resp = await request({ type: "pages.list", notebook_id: notebookId });
        setPages((resp.pages as Page[]) || []);
      } catch {
        // ignore
      } finally {
        setIsPagesLoading(false);
      }
    },
    [request]
  );

  useEffect(() => {
    loadNotebooks();
  }, [loadNotebooks]);

  useEffect(() => {
    if (selectedNotebook) {
      loadPages(selectedNotebook);
    }
  }, [selectedNotebook, loadPages]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("notebooks.created", () => loadNotebooks()));
    unsubs.push(subscribe("notebooks.updated", () => loadNotebooks()));
    unsubs.push(subscribe("notebooks.deleted", () => loadNotebooks()));
    unsubs.push(
      subscribe("pages.created", () => {
        if (selectedNotebook) loadPages(selectedNotebook);
        loadNotebooks(); // update page counts
      })
    );
    unsubs.push(
      subscribe("pages.updated", () => {
        if (selectedNotebook) loadPages(selectedNotebook);
      })
    );
    unsubs.push(
      subscribe("pages.deleted", () => {
        if (selectedNotebook) loadPages(selectedNotebook);
        loadNotebooks();
      })
    );
    unsubs.push(
      subscribe("pages.moved", () => {
        if (selectedNotebook) loadPages(selectedNotebook);
        loadNotebooks();
      })
    );
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadNotebooks, loadPages, selectedNotebook]);

  // Auto-save content with debounce
  const debouncedSave = useCallback(
    (pageId: string, title: string, content: string) => {
      if (saveTimeoutRef.current) {
        clearTimeout(saveTimeoutRef.current);
      }
      saveTimeoutRef.current = setTimeout(async () => {
        setIsSaving(true);
        try {
          await request({ type: "pages.update", page_id: pageId, title, content });
          setLastSaved(new Date());
        } catch {
          // ignore
        } finally {
          setIsSaving(false);
        }
      }, 800);
    },
    [request]
  );

  // Select a page for viewing (read-only by default)
  const openPage = useCallback(
    async (pageId: string) => {
      try {
        const resp = await request({ type: "pages.get", page_id: pageId });
        const page = resp.page as Page;
        setSelectedPage(page);
        setEditTitle(page.title);
        setEditContent(page.content);
        setLastSaved(null);
        setIsEditing(false);
      } catch {
        // ignore
      }
    },
    [request]
  );

  const handleCreateNotebook = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newNotebookTitle.trim()) return;
    setIsCreating(true);
    try {
      const resp = await request({
        type: "notebooks.create",
        title: newNotebookTitle,
        description: newNotebookDesc,
        color: newNotebookColor,
      });
      setShowCreateNotebook(false);
      setNewNotebookTitle("");
      setNewNotebookDesc("");
      setNewNotebookColor("blue");
      // Auto-select the new notebook
      if (resp.notebook) {
        setSelectedNotebook((resp.notebook as Notebook).id);
      }
      loadNotebooks();
    } finally {
      setIsCreating(false);
    }
  };

  const handleCreatePage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedNotebook) return;
    setIsCreating(true);
    try {
      const resp = await request({
        type: "pages.create",
        notebook_id: selectedNotebook,
        title: newPageTitle || "Untitled",
      });
      setShowCreatePage(false);
      setNewPageTitle("");
      loadPages(selectedNotebook);
      // Auto-open the new page in edit mode
      if (resp.page) {
        const page = resp.page as Page;
        setSelectedPage(page);
        setEditTitle(page.title);
        setEditContent(page.content || "");
        setLastSaved(null);
        setIsEditing(true);
      }
    } finally {
      setIsCreating(false);
    }
  };

  const handleDeleteNotebook = async () => {
    if (!deleteNotebook) return;
    try {
      await request({ type: "notebooks.delete", notebook_id: deleteNotebook });
      if (selectedNotebook === deleteNotebook) {
        setSelectedNotebook(null);
        setSelectedPage(null);
        setPages([]);
      }
      loadNotebooks();
    } finally {
      setDeleteNotebook(null);
    }
  };

  const handleDeletePage = async () => {
    if (!deletePage) return;
    try {
      await request({ type: "pages.delete", page_id: deletePage });
      if (selectedPage?.id === deletePage) {
        setSelectedPage(null);
        setIsEditing(false);
      }
      if (selectedNotebook) loadPages(selectedNotebook);
      loadNotebooks();
    } finally {
      setDeletePage(null);
    }
  };

  const handleTogglePin = async (page: Page) => {
    const newPinned = page.pinned !== "1";
    await request({ type: "pages.update", page_id: page.id, pinned: newPinned });
    if (selectedNotebook) loadPages(selectedNotebook);
    if (selectedPage?.id === page.id) {
      setSelectedPage({ ...selectedPage, pinned: newPinned ? "1" : "0" });
    }
  };

  const handleRenameNotebook = async (notebookId: string) => {
    if (!editNotebookTitle.trim()) {
      setEditingNotebook(null);
      return;
    }
    await request({ type: "notebooks.update", notebook_id: notebookId, title: editNotebookTitle });
    setEditingNotebook(null);
    loadNotebooks();
  };

  const handleMovePage = async (pageId: string, targetNotebookId: string) => {
    await request({ type: "pages.move", page_id: pageId, notebook_id: targetNotebookId });
    setMovePageId(null);
    if (selectedNotebook) loadPages(selectedNotebook);
    loadNotebooks();
  };

  const saveTitle = useCallback(async () => {
    if (!selectedPage) return;
    setIsSaving(true);
    try {
      await request({ type: "pages.update", page_id: selectedPage.id, title: editTitle, content: editContent });
      setLastSaved(new Date());
    } catch {
      // ignore
    } finally {
      setIsSaving(false);
      setIsEditingTitle(false);
    }
  }, [selectedPage, editTitle, editContent, request]);

  const handleContentChange = (value: string) => {
    setEditContent(value);
    if (selectedPage) {
      debouncedSave(selectedPage.id, editTitle, value);
    }
  };

  const handleTitleChange = (value: string) => {
    setEditTitle(value);
    if (selectedPage) {
      debouncedSave(selectedPage.id, value, editContent);
    }
  };

  // Handle checkbox toggling in content
  const handleCheckboxToggle = (lineIndex: number) => {
    const lines = editContent.split("\n");
    const line = lines[lineIndex];
    if (line.match(/^(\s*[-*]\s*)\[[ ]\]/)) {
      lines[lineIndex] = line.replace(/\[[ ]\]/, "[x]");
    } else if (line.match(/^(\s*[-*]\s*)\[x\]/i)) {
      lines[lineIndex] = line.replace(/\[x\]/i, "[ ]");
    }
    const newContent = lines.join("\n");
    setEditContent(newContent);
    if (selectedPage) {
      debouncedSave(selectedPage.id, editTitle, newContent);
    }
  };

  // Render content with checkbox support
  const renderContent = (content: string) => {
    const lines = content.split("\n");
    return lines.map((line, i) => {
      const checkboxMatch = line.match(/^(\s*[-*]\s*)\[([ x])\]\s*(.*)/i);
      if (checkboxMatch) {
        const checked = checkboxMatch[2].toLowerCase() === "x";
        const text = checkboxMatch[3];
        return (
          <div key={i} className="flex items-start gap-2 py-0.5">
            <button
              onClick={() => handleCheckboxToggle(i)}
              className={cn(
                "mt-0.5 w-4 h-4 rounded border flex-shrink-0 flex items-center justify-center transition-colors",
                checked
                  ? "bg-primary/20 border-primary/50 text-primary"
                  : "border-border/50 hover:border-primary/30"
              )}
            >
              {checked && <Check className="w-3 h-3" />}
            </button>
            <span className={cn("text-sm", checked && "line-through text-muted-foreground")}>{text}</span>
          </div>
        );
      }
      // Regular text
      if (line.trim() === "") return <div key={i} className="h-4" />;
      // Heading detection
      if (line.startsWith("### ")) return <h3 key={i} className="text-base font-semibold text-foreground mt-3 mb-1">{line.slice(4)}</h3>;
      if (line.startsWith("## ")) return <h2 key={i} className="text-lg font-semibold text-foreground mt-4 mb-1">{line.slice(3)}</h2>;
      if (line.startsWith("# ")) return <h1 key={i} className="text-xl font-bold text-foreground mt-4 mb-2">{line.slice(2)}</h1>;
      // Bullet
      if (line.match(/^\s*[-*]\s/)) {
        const text = line.replace(/^\s*[-*]\s/, "");
        return (
          <div key={i} className="flex items-start gap-2 py-0.5 pl-1">
            <span className="text-muted-foreground mt-1.5 w-1 h-1 rounded-full bg-muted-foreground flex-shrink-0" />
            <span className="text-sm">{text}</span>
          </div>
        );
      }
      return <p key={i} className="text-sm text-foreground/90 leading-relaxed">{line}</p>;
    });
  };

  // Pages table columns
  const pageColumns = useMemo<ColumnDef<Page, unknown>[]>(
    () => [
      {
        accessorKey: "title",
        header: "Title",
        cell: ({ row }) => {
          const page = row.original;
          const isPinned = page.pinned === "1";
          return (
            <div className="flex items-center gap-2.5">
              <div className={cn(
                "w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0",
                isPinned ? "bg-amber-500/10 text-amber-400" : "bg-primary/10 text-primary"
              )}>
                {isPinned ? <Pin className="w-4 h-4" /> : <FileText className="w-4 h-4" />}
              </div>
              <span className="text-sm font-medium text-foreground line-clamp-1">
                {page.title || "Untitled"}
              </span>
            </div>
          );
        },
      },
      {
        accessorKey: "content",
        header: "Preview",
        cell: ({ row }) => {
          const preview = row.original.content?.slice(0, 120)?.replace(/\n/g, " ") || "";
          return (
            <span className="text-xs text-muted-foreground line-clamp-1">
              {preview || "Empty page"}
            </span>
          );
        },
      },
      {
        accessorKey: "pinned",
        header: "Pinned",
        size: 80,
        cell: ({ row }) => (
          row.original.pinned === "1" ? (
            <span className="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded border bg-amber-500/10 text-amber-400 border-amber-500/20">
              Pinned
            </span>
          ) : (
            <span className="text-xs text-muted-foreground">-</span>
          )
        ),
      },
      {
        accessorFn: (row) => row.updated_at,
        id: "updated",
        header: "Updated",
        size: 120,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground whitespace-nowrap">
            {row.original.updated_at
              ? formatDistanceToNow(new Date(row.original.updated_at * 1000), { addSuffix: true })
              : "-"}
          </span>
        ),
      },
      {
        id: "actions",
        header: "",
        size: 100,
        cell: ({ row }) => {
          const page = row.original;
          const isPinned = page.pinned === "1";
          return (
            <div className="flex items-center justify-end gap-1">
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  handleTogglePin(page);
                }}
                className="p-1.5 rounded-lg hover:bg-white/5 text-muted-foreground"
                title={isPinned ? "Unpin" : "Pin"}
              >
                {isPinned ? <PinOff className="w-3.5 h-3.5" /> : <Pin className="w-3.5 h-3.5" />}
              </button>
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  setMovePageId(page.id);
                }}
                className="p-1.5 rounded-lg hover:bg-white/5 text-muted-foreground"
                title="Move to notebook"
              >
                <ArrowRightLeft className="w-3.5 h-3.5" />
              </button>
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  setDeletePage(page.id);
                }}
                className="p-1.5 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-red-400"
                title="Delete"
              >
                <Trash2 className="w-3.5 h-3.5" />
              </button>
            </div>
          );
        },
      },
    ],
    []
  );

  // ===== RENDER =====

  // Page detail view (preview or editor)
  if (selectedPage) {
    return (
      <Layout>
        <div className="flex-1 flex flex-col overflow-hidden">
          {/* Header */}
          <div className="flex items-center gap-3 px-4 md:px-6 h-14 border-b border-border/50 flex-shrink-0">
            <button
              onClick={() => {
                setSelectedPage(null);
                setIsEditing(false);
                setIsEditingTitle(false);
              }}
              className="p-1.5 rounded-lg hover:bg-white/5 text-muted-foreground"
            >
              <ChevronLeft className="w-5 h-5" />
            </button>
            {isEditing || isEditingTitle ? (
              <input
                value={editTitle}
                onChange={(e) => handleTitleChange(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") {
                    saveTitle();
                  }
                  if (e.key === "Escape") {
                    setIsEditingTitle(false);
                  }
                }}
                onBlur={() => {
                  if (isEditingTitle) saveTitle();
                }}
                className="flex-1 bg-transparent text-lg font-display font-bold text-foreground outline-none placeholder:text-muted-foreground/50"
                placeholder="Page title..."
                autoFocus={isEditingTitle}
              />
            ) : (
              <div className="flex-1 flex items-center gap-2 min-w-0 group/title">
                <h1
                  className="text-lg font-display font-bold text-foreground truncate cursor-pointer"
                  onDoubleClick={() => setIsEditingTitle(true)}
                >
                  {editTitle || "Untitled"}
                </h1>
                <button
                  onClick={() => setIsEditingTitle(true)}
                  className="p-1 rounded-lg opacity-0 group-hover/title:opacity-100 hover:bg-white/5 text-muted-foreground transition-opacity"
                  title="Edit title"
                >
                  <Pencil className="w-3.5 h-3.5" />
                </button>
              </div>
            )}
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              {isEditing && isSaving && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
              {isEditing && lastSaved && !isSaving && (
                <span>Saved {formatDistanceToNow(lastSaved, { addSuffix: true })}</span>
              )}
              {isEditing ? (
                <Button
                  variant="ghost"
                  size="sm"
                  className="rounded-lg text-xs h-8"
                  onClick={() => setIsEditing(false)}
                >
                  <Check className="w-3.5 h-3.5 mr-1" />
                  Done
                </Button>
              ) : (
                <Button
                  variant="ghost"
                  size="sm"
                  className="rounded-lg text-xs h-8"
                  onClick={() => setIsEditing(true)}
                >
                  <Pencil className="w-3.5 h-3.5 mr-1" />
                  Edit
                </Button>
              )}
              <button
                onClick={() => handleTogglePin(selectedPage)}
                className={cn(
                  "p-1.5 rounded-lg transition-colors",
                  selectedPage.pinned === "1"
                    ? "text-amber-400 hover:bg-amber-400/10"
                    : "text-muted-foreground hover:bg-white/5"
                )}
              >
                {selectedPage.pinned === "1" ? <Pin className="w-4 h-4" /> : <PinOff className="w-4 h-4" />}
              </button>
              <button
                onClick={() => setDeletePage(selectedPage.id)}
                className="p-1.5 rounded-lg text-muted-foreground hover:text-red-400 hover:bg-red-400/10"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            </div>
          </div>

          {/* Content area */}
          <div className="flex-1 flex flex-col overflow-hidden">
            {isEditing ? (
              /* Dual pane: editor + preview */
              <div className="flex-1 flex overflow-hidden">
                {/* Editor */}
                <div className="flex-1 flex flex-col min-w-0">
                  <div className="px-4 md:px-6 py-2 text-[10px] uppercase tracking-wider font-semibold text-muted-foreground border-b border-border/30">
                    Editor
                  </div>
                  <textarea
                    value={editContent}
                    onChange={(e) => handleContentChange(e.target.value)}
                    className="flex-1 bg-transparent text-sm text-foreground/90 leading-relaxed p-4 md:p-6 outline-none resize-none font-mono placeholder:text-muted-foreground/30"
                    placeholder={"Write your note here...\n\nUse markdown:\n- [ ] Todo item\n- [x] Done item\n# Heading\n- Bullet point"}
                  />
                </div>
                {/* Preview (hidden on mobile) */}
                <div className="hidden md:flex flex-1 flex-col min-w-0 border-l border-border/30">
                  <div className="px-4 md:px-6 py-2 text-[10px] uppercase tracking-wider font-semibold text-muted-foreground border-b border-border/30">
                    Preview
                  </div>
                  <div className="flex-1 overflow-auto p-4 md:p-6">
                    {editContent ? renderContent(editContent) : (
                      <p className="text-sm text-muted-foreground/50 italic">Nothing to preview</p>
                    )}
                  </div>
                </div>
              </div>
            ) : (
              /* Read-only preview */
              <div className="flex-1 overflow-auto p-4 md:p-8">
                <div className="max-w-3xl mx-auto">
                  {editContent ? renderContent(editContent) : (
                    <div className="flex flex-col items-center justify-center py-16">
                      <FileText className="w-12 h-12 text-muted-foreground/20 mb-3" />
                      <p className="text-sm text-muted-foreground mb-2">This page is empty</p>
                      <Button
                        variant="ghost"
                        className="text-primary hover:text-primary/90"
                        onClick={() => setIsEditing(true)}
                      >
                        <Pencil className="w-4 h-4 mr-1" />
                        Start writing
                      </Button>
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Delete page confirmation */}
        <Dialog open={deletePage !== null} onOpenChange={(open) => { if (!open) setDeletePage(null); }}>
          <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
            <DialogHeader>
              <DialogTitle>Delete Page</DialogTitle>
            </DialogHeader>
            <p className="text-sm text-muted-foreground py-2">
              Are you sure? This cannot be undone.
            </p>
            <DialogFooter>
              <Button variant="ghost" className="rounded-xl" onClick={() => setDeletePage(null)}>Cancel</Button>
              <Button className="rounded-xl bg-red-500 hover:bg-red-600 text-white" onClick={handleDeletePage}>Delete</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </Layout>
    );
  }

  // Main notes view: notebooks sidebar + pages table
  return (
    <Layout>
      <div className="flex-1 flex overflow-hidden">
        {/* Notebook sidebar */}
        <div className="w-64 md:w-72 flex-shrink-0 border-r border-border/50 flex flex-col bg-background/50">
          <div className="flex items-center justify-between px-4 py-3 border-b border-border/30">
            <h2 className="text-sm font-semibold text-foreground">Notebooks</h2>
            <button
              onClick={() => setShowCreateNotebook(true)}
              className="p-1.5 rounded-lg hover:bg-white/5 text-muted-foreground hover:text-foreground transition-colors"
            >
              <Plus className="w-4 h-4" />
            </button>
          </div>
          <div className="flex-1 overflow-y-auto py-2 px-2 space-y-0.5">
            {isLoading ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="w-5 h-5 animate-spin text-muted-foreground" />
              </div>
            ) : notebooks.length === 0 ? (
              <div className="text-center py-8 px-4">
                <BookOpen className="w-8 h-8 text-muted-foreground/30 mx-auto mb-2" />
                <p className="text-xs text-muted-foreground">No notebooks yet</p>
                <button
                  onClick={() => setShowCreateNotebook(true)}
                  className="text-xs text-primary hover:underline mt-1"
                >
                  Create one
                </button>
              </div>
            ) : (
              notebooks.map((nb) => {
                const colorCls = getColorClasses(nb.color);
                const isSelected = selectedNotebook === nb.id;
                const isEditingNb = editingNotebook === nb.id;

                return (
                  <div
                    key={nb.id}
                    className={cn(
                      "group relative flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm transition-all cursor-pointer",
                      isSelected
                        ? `${colorCls.bg} ${colorCls.text} ${colorCls.border} border`
                        : "text-muted-foreground hover:text-foreground hover:bg-white/5 border border-transparent"
                    )}
                    onClick={() => {
                      if (!isEditingNb) {
                        setSelectedNotebook(nb.id);
                        setSelectedPage(null);
                        setIsEditing(false);
                      }
                    }}
                  >
                    <BookOpen className={cn("w-4 h-4 flex-shrink-0", isSelected ? colorCls.text : "")} />
                    {isEditingNb ? (
                      <div className="flex-1 flex items-center gap-1">
                        <input
                          value={editNotebookTitle}
                          onChange={(e) => setEditNotebookTitle(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === "Enter") handleRenameNotebook(nb.id);
                            if (e.key === "Escape") setEditingNotebook(null);
                          }}
                          className="flex-1 bg-transparent text-sm outline-none"
                          autoFocus
                          onClick={(e) => e.stopPropagation()}
                        />
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            handleRenameNotebook(nb.id);
                          }}
                          className="p-0.5 rounded hover:bg-white/10"
                        >
                          <Check className="w-3 h-3" />
                        </button>
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            setEditingNotebook(null);
                          }}
                          className="p-0.5 rounded hover:bg-white/10"
                        >
                          <X className="w-3 h-3" />
                        </button>
                      </div>
                    ) : (
                      <>
                        <span className="flex-1 truncate font-medium">{nb.title}</span>
                        <span className="text-[10px] opacity-60">{nb.page_count}</span>
                        <div className="hidden group-hover:flex items-center gap-0.5">
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setEditingNotebook(nb.id);
                              setEditNotebookTitle(nb.title);
                            }}
                            className="p-0.5 rounded hover:bg-white/10"
                          >
                            <Pencil className="w-3 h-3" />
                          </button>
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setDeleteNotebook(nb.id);
                            }}
                            className="p-0.5 rounded hover:bg-white/10 hover:text-red-400"
                          >
                            <Trash2 className="w-3 h-3" />
                          </button>
                        </div>
                      </>
                    )}
                  </div>
                );
              })
            )}
          </div>
        </div>

        {/* Pages table / empty state */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {selectedNotebook ? (
            <div className="flex-1 overflow-auto p-4 md:p-6">
              <div className="max-w-5xl mx-auto space-y-6">
                <div>
                  <h2 className="text-xl md:text-2xl font-display font-bold text-foreground">
                    {notebooks.find((n) => n.id === selectedNotebook)?.title || "Notebook"}
                  </h2>
                  <p className="text-sm text-muted-foreground mt-1">
                    {pages.length} {pages.length === 1 ? "page" : "pages"}
                  </p>
                </div>

                <DataTable
                  columns={pageColumns}
                  data={pages}
                  isLoading={isPagesLoading}
                  searchPlaceholder="Search pages..."
                  onRowClick={(row) => openPage(row.id)}
                  emptyMessage="No pages yet. Create your first page!"
                  headerActions={
                    <Button
                      onClick={() => setShowCreatePage(true)}
                      className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl h-9"
                    >
                      <Plus className="w-4 h-4 mr-2" />
                      New Page
                    </Button>
                  }
                />
              </div>
            </div>
          ) : (
            <div className="flex-1 flex flex-col items-center justify-center text-center px-8">
              <BookOpen className="w-16 h-16 text-muted-foreground/15 mb-4" />
              <h2 className="text-xl font-display font-bold text-foreground mb-2">Notes</h2>
              <p className="text-sm text-muted-foreground max-w-md mb-6">
                Organize your thoughts, todo lists, and documentation in notebooks.
                Select a notebook to get started, or create a new one.
              </p>
              <Button
                onClick={() => setShowCreateNotebook(true)}
                className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl"
              >
                <Plus className="w-4 h-4 mr-2" />
                Create Notebook
              </Button>
            </div>
          )}
        </div>
      </div>

      {/* Create Notebook Dialog */}
      <Dialog open={showCreateNotebook} onOpenChange={setShowCreateNotebook}>
        <DialogContent className="sm:max-w-lg bg-card border-border/50 rounded-2xl">
          <form onSubmit={handleCreateNotebook}>
            <DialogHeader>
              <DialogTitle>New Notebook</DialogTitle>
            </DialogHeader>
            <div className="py-6 space-y-4">
              <div>
                <label className="text-sm font-medium mb-1.5 block">Title</label>
                <Input
                  value={newNotebookTitle}
                  onChange={(e) => setNewNotebookTitle(e.target.value)}
                  placeholder="My Notebook"
                  className="bg-background/50 border-border/50 rounded-xl"
                  autoFocus
                />
              </div>
              <div>
                <label className="text-sm font-medium mb-1.5 block">Description</label>
                <Input
                  value={newNotebookDesc}
                  onChange={(e) => setNewNotebookDesc(e.target.value)}
                  placeholder="Optional description..."
                  className="bg-background/50 border-border/50 rounded-xl"
                />
              </div>
              <div>
                <label className="text-sm font-medium mb-1.5 block">Color</label>
                <div className="flex flex-wrap gap-2">
                  {NOTEBOOK_COLORS.map((c) => (
                    <button
                      key={c.value}
                      type="button"
                      onClick={() => setNewNotebookColor(c.value)}
                      className={cn(
                        "px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors",
                        newNotebookColor === c.value
                          ? `${c.bg} ${c.text} ${c.border}`
                          : "border-border/50 text-muted-foreground hover:border-border"
                      )}
                    >
                      {c.label}
                    </button>
                  ))}
                </div>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => setShowCreateNotebook(false)} className="rounded-xl">
                Cancel
              </Button>
              <Button type="submit" disabled={isCreating || !newNotebookTitle.trim()} className="rounded-xl">
                {isCreating ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
                Create
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Create Page Dialog */}
      <Dialog open={showCreatePage} onOpenChange={setShowCreatePage}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <form onSubmit={handleCreatePage}>
            <DialogHeader>
              <DialogTitle>New Page</DialogTitle>
            </DialogHeader>
            <div className="py-6">
              <label className="text-sm font-medium mb-1.5 block">Title</label>
              <Input
                value={newPageTitle}
                onChange={(e) => setNewPageTitle(e.target.value)}
                placeholder="Page title..."
                className="bg-background/50 border-border/50 rounded-xl"
                autoFocus
              />
            </div>
            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => setShowCreatePage(false)} className="rounded-xl">
                Cancel
              </Button>
              <Button type="submit" disabled={isCreating} className="rounded-xl">
                {isCreating ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
                Create
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Notebook Confirmation */}
      <Dialog open={deleteNotebook !== null} onOpenChange={(open) => { if (!open) setDeleteNotebook(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Delete Notebook</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            This will delete the notebook and all its pages. This cannot be undone.
          </p>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setDeleteNotebook(null)}>Cancel</Button>
            <Button className="rounded-xl bg-red-500 hover:bg-red-600 text-white" onClick={handleDeleteNotebook}>
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Page Confirmation */}
      <Dialog open={deletePage !== null} onOpenChange={(open) => { if (!open) setDeletePage(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Delete Page</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            Are you sure? This cannot be undone.
          </p>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setDeletePage(null)}>Cancel</Button>
            <Button className="rounded-xl bg-red-500 hover:bg-red-600 text-white" onClick={handleDeletePage}>Delete</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Move Page Dialog */}
      <Dialog open={movePageId !== null} onOpenChange={(open) => { if (!open) setMovePageId(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Move Page</DialogTitle>
          </DialogHeader>
          <div className="py-4 space-y-2">
            <p className="text-sm text-muted-foreground mb-3">Select a destination notebook:</p>
            {notebooks
              .filter((nb) => nb.id !== selectedNotebook)
              .map((nb) => {
                const colorCls = getColorClasses(nb.color);
                return (
                  <button
                    key={nb.id}
                    onClick={() => movePageId && handleMovePage(movePageId, nb.id)}
                    className={cn(
                      "w-full flex items-center gap-3 px-4 py-3 rounded-xl border text-left transition-colors",
                      "border-border/50 hover:border-primary/30 hover:bg-white/[0.02]"
                    )}
                  >
                    <BookOpen className={cn("w-4 h-4", colorCls.text)} />
                    <span className="text-sm font-medium">{nb.title}</span>
                    <span className="text-[10px] text-muted-foreground ml-auto">{nb.page_count} pages</span>
                  </button>
                );
              })}
            {notebooks.filter((nb) => nb.id !== selectedNotebook).length === 0 && (
              <p className="text-sm text-muted-foreground text-center py-4">
                No other notebooks to move to.
              </p>
            )}
          </div>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setMovePageId(null)}>Cancel</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
