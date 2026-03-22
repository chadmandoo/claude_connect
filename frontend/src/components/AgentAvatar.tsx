import { cn } from "@/lib/utils";

interface AgentAvatarProps {
  color: string;
  name: string;
  icon?: string;
  size?: "sm" | "md" | "lg";
  className?: string;
}

const sizeClasses = {
  sm: "w-6 h-6 text-[10px]",
  md: "w-8 h-8 text-xs",
  lg: "w-10 h-10 text-sm",
};

export function AgentAvatar({ color, name, size = "md", className }: AgentAvatarProps) {
  return (
    <div
      className={cn(
        "rounded-lg flex items-center justify-center font-bold text-white flex-shrink-0",
        sizeClasses[size],
        className
      )}
      style={{ backgroundColor: color || "#6366f1" }}
    >
      {name?.charAt(0)?.toUpperCase() || "?"}
    </div>
  );
}
