import { Switch, Route, Redirect } from "wouter";
import { Toaster } from "sonner";
import { WebSocketProvider, useWs } from "@/hooks/useWebSocket";
import { LoginOverlay } from "@/components/LoginOverlay";
import NotFound from "@/pages/not-found";

// Page Imports
import Conversations from "@/pages/conversations";
import ConversationDetail from "@/pages/conversations/[id]";
import Channels from "@/pages/channels";
import ChannelDetail from "@/pages/channels/[id]";
import Projects from "@/pages/projects";
import ProjectKanban from "@/pages/projects/[id]";
import Memory from "@/pages/memory";
import MemoryDetail from "@/pages/memory/[id]";
import Skills from "@/pages/skills";
import Tasks from "@/pages/tasks";
import Agents from "@/pages/agents";
import AgentDetail from "@/pages/agents/[id]";
import Notes from "@/pages/notes";
import Todos from "@/pages/todos";
import SystemDocs from "@/pages/system";
import SystemDocDetail from "@/pages/system/[slug]";

function AuthenticatedRouter() {
  const { status } = useWs();

  if (status !== "authenticated") {
    return <LoginOverlay />;
  }

  return (
    <Switch>
      <Route path="/">
        <Redirect to="/notes" />
      </Route>

      <Route path="/conversations" component={Conversations} />
      <Route path="/conversations/:id" component={ConversationDetail} />

      <Route path="/channels" component={Channels} />
      <Route path="/channels/:id" component={ChannelDetail} />

      <Route path="/projects" component={Projects} />
      <Route path="/projects/:id" component={ProjectKanban} />

      <Route path="/notes" component={Notes} />
      <Route path="/todos" component={Todos} />
      <Route path="/tasks" component={Tasks} />
      <Route path="/agents" component={Agents} />
      <Route path="/agents/:id" component={AgentDetail} />
      <Route path="/memory" component={Memory} />
      <Route path="/memory/:id" component={MemoryDetail} />
      <Route path="/skills" component={Skills} />
      <Route path="/system" component={SystemDocs} />
      <Route path="/system/:slug" component={SystemDocDetail} />

      <Route component={NotFound} />
    </Switch>
  );
}

function App() {
  return (
    <WebSocketProvider>
      <AuthenticatedRouter />
      <Toaster
        theme="dark"
        position="bottom-right"
        toastOptions={{
          style: {
            background: "hsl(222 47% 7%)",
            border: "1px solid hsl(216 34% 17%)",
            color: "hsl(213 31% 91%)",
          },
        }}
      />
    </WebSocketProvider>
  );
}

export default App;
