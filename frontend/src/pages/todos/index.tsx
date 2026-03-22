import { useState, useEffect, useCallback, useRef } from "react";
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
import {
  Plus,
  Loader2,
  Trash2,
  Check,
  ChevronDown,
  ChevronRight,
  CircleDot,
  Pencil,
  X,
  CheckCircle2,
  Circle,
  ArrowRightLeft,
  Eraser,
} from "lucide-react";
import { useWs } from "@/hooks/useWebSocket";
import { cn } from "@/lib/utils";

interface TodoItem {
  id: string;
  section_id: string;
  title: string;
  note: string;
  done: string; // '0' or '1'
  priority: string;
  sort_order: number;
  due_date: number;
  created_at: number;
  updated_at: number;
  completed_at: number;
}

interface TodoSection {
  id: string;
  title: string;
  color: string;
  sort_order: number;
  collapsed: string; // '0' or '1'
  items: TodoItem[];
  counts: { total: number; done: number; remaining: number };
  created_at: number;
  updated_at: number;
}

const SECTION_COLORS: { value: string; dot: string; bg: string; border: string; text: string }[] = [
  { value: "slate", dot: "bg-slate-400", bg: "bg-slate-500/10", border: "border-slate-500/30", text: "text-slate-400" },
  { value: "blue", dot: "bg-blue-400", bg: "bg-blue-500/10", border: "border-blue-500/30", text: "text-blue-400" },
  { value: "purple", dot: "bg-purple-400", bg: "bg-purple-500/10", border: "border-purple-500/30", text: "text-purple-400" },
  { value: "green", dot: "bg-emerald-400", bg: "bg-emerald-500/10", border: "border-emerald-500/30", text: "text-emerald-400" },
  { value: "orange", dot: "bg-orange-400", bg: "bg-orange-500/10", border: "border-orange-500/30", text: "text-orange-400" },
  { value: "red", dot: "bg-red-400", bg: "bg-red-500/10", border: "border-red-500/30", text: "text-red-400" },
  { value: "pink", dot: "bg-pink-400", bg: "bg-pink-500/10", border: "border-pink-500/30", text: "text-pink-400" },
  { value: "amber", dot: "bg-amber-400", bg: "bg-amber-500/10", border: "border-amber-500/30", text: "text-amber-400" },
];

function getColor(color: string) {
  return SECTION_COLORS.find((c) => c.value === color) || SECTION_COLORS[0];
}

const PRIORITY_STYLES: Record<string, string> = {
  low: "text-slate-500",
  normal: "text-blue-400",
  high: "text-orange-400",
  urgent: "text-red-400",
};

export default function TodosPage() {
  const { request, subscribe } = useWs();
  const [sections, setSections] = useState<TodoSection[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  // New section dialog
  const [showNewSection, setShowNewSection] = useState(false);
  const [newSectionTitle, setNewSectionTitle] = useState("");
  const [newSectionColor, setNewSectionColor] = useState("blue");
  const [isCreatingSection, setIsCreatingSection] = useState(false);

  // Inline add item
  const [addingToSection, setAddingToSection] = useState<string | null>(null);
  const [newItemTitle, setNewItemTitle] = useState("");
  const addInputRef = useRef<HTMLInputElement>(null);

  // Edit section
  const [editingSectionId, setEditingSectionId] = useState<string | null>(null);
  const [editSectionTitle, setEditSectionTitle] = useState("");

  // Edit item
  const [editingItemId, setEditingItemId] = useState<string | null>(null);
  const [editItemTitle, setEditItemTitle] = useState("");

  // Delete confirmations
  const [deleteSection, setDeleteSection] = useState<string | null>(null);

  // Move item
  const [moveItem, setMoveItem] = useState<{ itemId: string; currentSectionId: string } | null>(null);

  const loadTodos = useCallback(async () => {
    try {
      const resp = await request({ type: "todos.list" });
      setSections((resp.sections as TodoSection[]) || []);
    } catch {
      // ignore
    } finally {
      setIsLoading(false);
    }
  }, [request]);

  useEffect(() => {
    loadTodos();
  }, [loadTodos]);

  useEffect(() => {
    const unsubs: (() => void)[] = [];
    unsubs.push(subscribe("todos.sectionCreated", () => loadTodos()));
    unsubs.push(subscribe("todos.sectionUpdated", () => loadTodos()));
    unsubs.push(subscribe("todos.sectionDeleted", () => loadTodos()));
    unsubs.push(subscribe("todos.itemCreated", () => loadTodos()));
    unsubs.push(subscribe("todos.itemUpdated", () => loadTodos()));
    unsubs.push(subscribe("todos.itemToggled", () => loadTodos()));
    unsubs.push(subscribe("todos.itemDeleted", () => loadTodos()));
    unsubs.push(subscribe("todos.itemMoved", () => loadTodos()));
    unsubs.push(subscribe("todos.completedCleared", () => loadTodos()));
    return () => unsubs.forEach((u) => u());
  }, [subscribe, loadTodos]);

  // Focus add input when section is selected
  useEffect(() => {
    if (addingToSection && addInputRef.current) {
      addInputRef.current.focus();
    }
  }, [addingToSection]);

  const handleCreateSection = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newSectionTitle.trim()) return;
    setIsCreatingSection(true);
    try {
      await request({
        type: "todos.createSection",
        title: newSectionTitle,
        color: newSectionColor,
      });
      setShowNewSection(false);
      setNewSectionTitle("");
      setNewSectionColor("blue");
      loadTodos();
    } finally {
      setIsCreatingSection(false);
    }
  };

  const handleAddItem = async (sectionId: string) => {
    if (!newItemTitle.trim()) {
      setAddingToSection(null);
      return;
    }
    await request({
      type: "todos.createItem",
      section_id: sectionId,
      title: newItemTitle,
    });
    setNewItemTitle("");
    loadTodos();
    // Keep focus for adding more items
    setTimeout(() => addInputRef.current?.focus(), 50);
  };

  const handleToggle = async (itemId: string) => {
    await request({ type: "todos.toggleItem", item_id: itemId });
    loadTodos();
  };

  const handleDeleteItem = async (itemId: string) => {
    await request({ type: "todos.deleteItem", item_id: itemId });
    loadTodos();
  };

  const handleDeleteSection = async () => {
    if (!deleteSection) return;
    await request({ type: "todos.deleteSection", section_id: deleteSection });
    setDeleteSection(null);
    loadTodos();
  };

  const handleToggleCollapse = async (sectionId: string, currentCollapsed: string) => {
    const newCollapsed = currentCollapsed !== "1";
    await request({ type: "todos.updateSection", section_id: sectionId, collapsed: newCollapsed });
    loadTodos();
  };

  const handleRenameSection = async (sectionId: string) => {
    if (!editSectionTitle.trim()) {
      setEditingSectionId(null);
      return;
    }
    await request({ type: "todos.updateSection", section_id: sectionId, title: editSectionTitle });
    setEditingSectionId(null);
    loadTodos();
  };

  const handleRenameItem = async (itemId: string) => {
    if (!editItemTitle.trim()) {
      setEditingItemId(null);
      return;
    }
    await request({ type: "todos.updateItem", item_id: itemId, title: editItemTitle });
    setEditingItemId(null);
    loadTodos();
  };

  const handleClearCompleted = async (sectionId: string) => {
    await request({ type: "todos.clearCompleted", section_id: sectionId });
    loadTodos();
  };

  const handleMoveItem = async (itemId: string, targetSectionId: string) => {
    await request({ type: "todos.moveItem", item_id: itemId, section_id: targetSectionId });
    setMoveItem(null);
    loadTodos();
  };

  // Totals
  const totalItems = sections.reduce((sum, s) => sum + s.counts.total, 0);
  const totalDone = sections.reduce((sum, s) => sum + s.counts.done, 0);
  const totalRemaining = totalItems - totalDone;

  return (
    <Layout>
      <div className="flex-1 overflow-auto p-4 md:p-8 pb-20 md:pb-8">
        <div className="max-w-3xl mx-auto space-y-6">
          {/* Header */}
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-2xl md:text-3xl font-display font-bold text-foreground">
                Todo List
              </h1>
              {totalItems > 0 && (
                <p className="text-muted-foreground mt-1 text-sm">
                  {totalRemaining} remaining &middot; {totalDone} completed
                </p>
              )}
            </div>
            <Button
              onClick={() => setShowNewSection(true)}
              className="bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg shadow-primary/20 rounded-xl h-9"
            >
              <Plus className="w-4 h-4 mr-2" />
              New Section
            </Button>
          </div>

          {/* Sections */}
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="w-6 h-6 animate-spin text-muted-foreground" />
            </div>
          ) : sections.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-20">
              <CheckCircle2 className="w-16 h-16 text-muted-foreground/15 mb-4" />
              <h2 className="text-lg font-display font-bold text-foreground mb-2">No sections yet</h2>
              <p className="text-sm text-muted-foreground mb-6 max-w-md text-center">
                Create sections to organize your tasks. For example: "Today", "This Week", "Shopping", etc.
              </p>
              <Button
                onClick={() => setShowNewSection(true)}
                className="bg-primary hover:bg-primary/90 text-primary-foreground rounded-xl"
              >
                <Plus className="w-4 h-4 mr-2" />
                Create your first section
              </Button>
            </div>
          ) : (
            <div className="space-y-4">
              {sections.map((section) => {
                const color = getColor(section.color);
                const isCollapsed = section.collapsed === "1";
                const isEditing = editingSectionId === section.id;
                const doneCount = section.counts.done;
                const totalCount = section.counts.total;

                return (
                  <div
                    key={section.id}
                    className="rounded-xl border border-border/50 overflow-hidden"
                  >
                    {/* Section header */}
                    <div
                      className={cn(
                        "flex items-center gap-3 px-4 py-3 cursor-pointer select-none transition-colors",
                        "hover:bg-white/[0.02]"
                      )}
                      onClick={() => {
                        if (!isEditing) handleToggleCollapse(section.id, section.collapsed);
                      }}
                    >
                      <button className="text-muted-foreground">
                        {isCollapsed ? (
                          <ChevronRight className="w-4 h-4" />
                        ) : (
                          <ChevronDown className="w-4 h-4" />
                        )}
                      </button>
                      <div className={cn("w-2.5 h-2.5 rounded-full flex-shrink-0", color.dot)} />

                      {isEditing ? (
                        <div className="flex-1 flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                          <input
                            value={editSectionTitle}
                            onChange={(e) => setEditSectionTitle(e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === "Enter") handleRenameSection(section.id);
                              if (e.key === "Escape") setEditingSectionId(null);
                            }}
                            className="flex-1 bg-transparent text-sm font-semibold outline-none"
                            autoFocus
                          />
                          <button onClick={() => handleRenameSection(section.id)} className="p-1 rounded hover:bg-white/10">
                            <Check className="w-3.5 h-3.5 text-primary" />
                          </button>
                          <button onClick={() => setEditingSectionId(null)} className="p-1 rounded hover:bg-white/10">
                            <X className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      ) : (
                        <>
                          <span className="flex-1 text-sm font-semibold text-foreground">{section.title}</span>
                          {totalCount > 0 && (
                            <span className="text-xs text-muted-foreground">
                              {doneCount}/{totalCount}
                            </span>
                          )}
                        </>
                      )}

                      {!isEditing && (
                        <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 hover:opacity-100" style={{ opacity: undefined }}
                          onClick={(e) => e.stopPropagation()}
                        >
                          <button
                            onClick={() => {
                              setAddingToSection(section.id);
                              setNewItemTitle("");
                            }}
                            className="p-1 rounded hover:bg-white/10 text-muted-foreground hover:text-foreground"
                            title="Add item"
                          >
                            <Plus className="w-3.5 h-3.5" />
                          </button>
                          {doneCount > 0 && (
                            <button
                              onClick={() => handleClearCompleted(section.id)}
                              className="p-1 rounded hover:bg-white/10 text-muted-foreground hover:text-foreground"
                              title="Clear completed"
                            >
                              <Eraser className="w-3.5 h-3.5" />
                            </button>
                          )}
                          <button
                            onClick={() => {
                              setEditingSectionId(section.id);
                              setEditSectionTitle(section.title);
                            }}
                            className="p-1 rounded hover:bg-white/10 text-muted-foreground hover:text-foreground"
                            title="Rename"
                          >
                            <Pencil className="w-3.5 h-3.5" />
                          </button>
                          <button
                            onClick={() => setDeleteSection(section.id)}
                            className="p-1 rounded hover:bg-white/10 text-muted-foreground hover:text-red-400"
                            title="Delete section"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      )}
                    </div>

                    {/* Section items */}
                    {!isCollapsed && (
                      <div className="border-t border-border/30">
                        {section.items.length === 0 && addingToSection !== section.id ? (
                          <div className="px-4 py-6 text-center">
                            <p className="text-xs text-muted-foreground/50 mb-2">No items</p>
                            <button
                              onClick={() => {
                                setAddingToSection(section.id);
                                setNewItemTitle("");
                              }}
                              className="text-xs text-primary hover:underline"
                            >
                              Add one
                            </button>
                          </div>
                        ) : (
                          <div className="divide-y divide-border/20">
                            {section.items.map((item) => {
                              const isDone = item.done === "1";
                              const isItemEditing = editingItemId === item.id;

                              return (
                                <div
                                  key={item.id}
                                  className={cn(
                                    "group flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-white/[0.02]",
                                    isDone && "opacity-50"
                                  )}
                                >
                                  <button
                                    onClick={() => handleToggle(item.id)}
                                    className={cn(
                                      "flex-shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all",
                                      isDone
                                        ? "bg-primary/20 border-primary/50 text-primary"
                                        : "border-border/50 hover:border-primary/40"
                                    )}
                                  >
                                    {isDone && <Check className="w-3 h-3" />}
                                  </button>

                                  {isItemEditing ? (
                                    <div className="flex-1 flex items-center gap-2">
                                      <input
                                        value={editItemTitle}
                                        onChange={(e) => setEditItemTitle(e.target.value)}
                                        onKeyDown={(e) => {
                                          if (e.key === "Enter") handleRenameItem(item.id);
                                          if (e.key === "Escape") setEditingItemId(null);
                                        }}
                                        className="flex-1 bg-transparent text-sm outline-none"
                                        autoFocus
                                      />
                                      <button onClick={() => handleRenameItem(item.id)} className="p-0.5 rounded hover:bg-white/10">
                                        <Check className="w-3.5 h-3.5 text-primary" />
                                      </button>
                                      <button onClick={() => setEditingItemId(null)} className="p-0.5 rounded hover:bg-white/10">
                                        <X className="w-3.5 h-3.5" />
                                      </button>
                                    </div>
                                  ) : (
                                    <>
                                      <span
                                        className={cn(
                                          "flex-1 text-sm",
                                          isDone ? "line-through text-muted-foreground" : "text-foreground"
                                        )}
                                      >
                                        {item.title}
                                      </span>
                                      <div className="hidden group-hover:flex items-center gap-0.5">
                                        <button
                                          onClick={() => {
                                            setEditingItemId(item.id);
                                            setEditItemTitle(item.title);
                                          }}
                                          className="p-1 rounded hover:bg-white/10 text-muted-foreground"
                                        >
                                          <Pencil className="w-3 h-3" />
                                        </button>
                                        {sections.length > 1 && (
                                          <button
                                            onClick={() => setMoveItem({ itemId: item.id, currentSectionId: section.id })}
                                            className="p-1 rounded hover:bg-white/10 text-muted-foreground"
                                          >
                                            <ArrowRightLeft className="w-3 h-3" />
                                          </button>
                                        )}
                                        <button
                                          onClick={() => handleDeleteItem(item.id)}
                                          className="p-1 rounded hover:bg-white/10 text-muted-foreground hover:text-red-400"
                                        >
                                          <Trash2 className="w-3 h-3" />
                                        </button>
                                      </div>
                                    </>
                                  )}
                                </div>
                              );
                            })}

                            {/* Inline add item */}
                            {addingToSection === section.id && (
                              <div className="flex items-center gap-3 px-4 py-2.5">
                                <Circle className="w-5 h-5 text-border/50 flex-shrink-0" />
                                <input
                                  ref={addInputRef}
                                  value={newItemTitle}
                                  onChange={(e) => setNewItemTitle(e.target.value)}
                                  onKeyDown={(e) => {
                                    if (e.key === "Enter") handleAddItem(section.id);
                                    if (e.key === "Escape") {
                                      setAddingToSection(null);
                                      setNewItemTitle("");
                                    }
                                  }}
                                  onBlur={() => {
                                    if (newItemTitle.trim()) {
                                      handleAddItem(section.id);
                                    } else {
                                      setAddingToSection(null);
                                    }
                                  }}
                                  placeholder="Add item... (Enter to save, Esc to cancel)"
                                  className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground/40"
                                />
                              </div>
                            )}
                          </div>
                        )}

                        {/* Add item button at bottom of section */}
                        {addingToSection !== section.id && section.items.length > 0 && (
                          <button
                            onClick={() => {
                              setAddingToSection(section.id);
                              setNewItemTitle("");
                            }}
                            className="w-full flex items-center gap-3 px-4 py-2 text-muted-foreground/40 hover:text-muted-foreground transition-colors text-sm border-t border-border/20"
                          >
                            <Plus className="w-4 h-4" />
                            Add item
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>

      {/* New Section Dialog */}
      <Dialog open={showNewSection} onOpenChange={setShowNewSection}>
        <DialogContent className="sm:max-w-lg bg-card border-border/50 rounded-2xl">
          <form onSubmit={handleCreateSection}>
            <DialogHeader>
              <DialogTitle>New Section</DialogTitle>
            </DialogHeader>
            <div className="py-6 space-y-4">
              <div>
                <label className="text-sm font-medium mb-1.5 block">Title</label>
                <Input
                  value={newSectionTitle}
                  onChange={(e) => setNewSectionTitle(e.target.value)}
                  placeholder="e.g. Today, This Week, Shopping..."
                  className="bg-background/50 border-border/50 rounded-xl"
                  autoFocus
                />
              </div>
              <div>
                <label className="text-sm font-medium mb-1.5 block">Color</label>
                <div className="flex flex-wrap gap-2">
                  {SECTION_COLORS.map((c) => (
                    <button
                      key={c.value}
                      type="button"
                      onClick={() => setNewSectionColor(c.value)}
                      className={cn(
                        "flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors",
                        newSectionColor === c.value
                          ? `${c.bg} ${c.text} ${c.border}`
                          : "border-border/50 text-muted-foreground hover:border-border"
                      )}
                    >
                      <div className={cn("w-2 h-2 rounded-full", c.dot)} />
                      {c.value.charAt(0).toUpperCase() + c.value.slice(1)}
                    </button>
                  ))}
                </div>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => setShowNewSection(false)} className="rounded-xl">
                Cancel
              </Button>
              <Button type="submit" disabled={isCreatingSection || !newSectionTitle.trim()} className="rounded-xl">
                {isCreatingSection ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
                Create
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Section Confirmation */}
      <Dialog open={deleteSection !== null} onOpenChange={(open) => { if (!open) setDeleteSection(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Delete Section</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground py-2">
            This will delete the section and all its items. This cannot be undone.
          </p>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setDeleteSection(null)}>Cancel</Button>
            <Button className="rounded-xl bg-red-500 hover:bg-red-600 text-white" onClick={handleDeleteSection}>Delete</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Move Item Dialog */}
      <Dialog open={moveItem !== null} onOpenChange={(open) => { if (!open) setMoveItem(null); }}>
        <DialogContent className="sm:max-w-md bg-card border-border/50 rounded-2xl">
          <DialogHeader>
            <DialogTitle>Move Item</DialogTitle>
          </DialogHeader>
          <div className="py-4 space-y-2">
            <p className="text-sm text-muted-foreground mb-3">Move to section:</p>
            {sections
              .filter((s) => s.id !== moveItem?.currentSectionId)
              .map((s) => {
                const color = getColor(s.color);
                return (
                  <button
                    key={s.id}
                    onClick={() => moveItem && handleMoveItem(moveItem.itemId, s.id)}
                    className="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-border/50 hover:border-primary/30 hover:bg-white/[0.02] text-left transition-colors"
                  >
                    <div className={cn("w-2.5 h-2.5 rounded-full", color.dot)} />
                    <span className="text-sm font-medium">{s.title}</span>
                    <span className="text-[10px] text-muted-foreground ml-auto">
                      {s.counts.remaining} items
                    </span>
                  </button>
                );
              })}
          </div>
          <DialogFooter>
            <Button variant="ghost" className="rounded-xl" onClick={() => setMoveItem(null)}>Cancel</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Layout>
  );
}
