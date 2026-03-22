import { createContext, useContext, useCallback, useEffect, useRef, useState, type ReactNode } from "react";

export type ConnectionStatus = "disconnected" | "connecting" | "connected" | "authenticated";

export interface WsMessage {
  type: string;
  id?: number | null;
  [key: string]: unknown;
}

interface PendingRequest {
  resolve: (data: WsMessage) => void;
  reject: (error: Error) => void;
  timer: ReturnType<typeof setTimeout>;
}

interface WebSocketContextType {
  status: ConnectionStatus;
  userId: string | null;
  send: (msg: WsMessage) => void;
  request: (msg: WsMessage, timeoutMs?: number) => Promise<WsMessage>;
  subscribe: (type: string, handler: (msg: WsMessage) => void) => () => void;
  authenticate: (password: string) => void;
}

const WebSocketContext = createContext<WebSocketContextType | null>(null);

export function WebSocketProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<ConnectionStatus>("disconnected");
  const [userId, setUserId] = useState<string | null>(null);
  const wsRef = useRef<WebSocket | null>(null);
  const pendingRef = useRef<Map<number, PendingRequest>>(new Map());
  const listenersRef = useRef<Map<string, Set<(msg: WsMessage) => void>>>(new Map());
  const nextIdRef = useRef(1);
  const reconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const reconnectDelayRef = useRef(1000);

  const getWsUrl = useCallback(() => {
    const proto = window.location.protocol === "https:" ? "wss:" : "ws:";
    const host = window.location.host;
    const token = localStorage.getItem("cc_token") || "";
    return `${proto}//${host}/?token=${encodeURIComponent(token)}`;
  }, []);

  const connect = useCallback(() => {
    if (wsRef.current?.readyState === WebSocket.OPEN || wsRef.current?.readyState === WebSocket.CONNECTING) return;

    setStatus("connecting");
    const ws = new WebSocket(getWsUrl());
    wsRef.current = ws;

    ws.onopen = () => {
      reconnectDelayRef.current = 1000;
      setStatus("connected");
    };

    ws.onmessage = (event) => {
      let msg: WsMessage;
      try {
        msg = JSON.parse(event.data);
      } catch {
        return;
      }

      // Handle auth responses
      if (msg.type === "auth.ok") {
        setStatus("authenticated");
        setUserId(msg.user_id as string);
        if (msg.token) {
          localStorage.setItem("cc_token", msg.token as string);
        }
        if (msg.user_id) {
          localStorage.setItem("cc_user_id", msg.user_id as string);
        }
      } else if (msg.type === "auth.required") {
        setStatus("connected");
        setUserId(null);
      } else if (msg.type === "auth.error") {
        setStatus("connected");
        setUserId(null);
      } else if (msg.type === "ping") {
        ws.send(JSON.stringify({ type: "pong" }));
        return;
      }

      // Resolve pending request if matching id
      if (msg.id != null) {
        const pending = pendingRef.current.get(msg.id as number);
        if (pending) {
          clearTimeout(pending.timer);
          pendingRef.current.delete(msg.id as number);
          pending.resolve(msg);
        }
      }

      // Notify subscribers
      const handlers = listenersRef.current.get(msg.type);
      if (handlers) {
        handlers.forEach((h) => h(msg));
      }
      // Also notify wildcard subscribers
      const wildcardHandlers = listenersRef.current.get("*");
      if (wildcardHandlers) {
        wildcardHandlers.forEach((h) => h(msg));
      }
    };

    ws.onclose = () => {
      wsRef.current = null;
      setStatus("disconnected");
      // Reject all pending
      pendingRef.current.forEach((p) => {
        clearTimeout(p.timer);
        p.reject(new Error("WebSocket closed"));
      });
      pendingRef.current.clear();

      // Reconnect with backoff
      reconnectTimerRef.current = setTimeout(() => {
        reconnectDelayRef.current = Math.min(reconnectDelayRef.current * 1.5, 15000);
        connect();
      }, reconnectDelayRef.current);
    };

    ws.onerror = () => {
      // onclose will fire after onerror
    };
  }, [getWsUrl]);

  useEffect(() => {
    connect();
    return () => {
      if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
      wsRef.current?.close();
    };
  }, [connect]);

  const send = useCallback((msg: WsMessage) => {
    if (wsRef.current?.readyState === WebSocket.OPEN) {
      wsRef.current.send(JSON.stringify(msg));
    }
  }, []);

  const request = useCallback((msg: WsMessage, timeoutMs = 30000): Promise<WsMessage> => {
    return new Promise((resolve, reject) => {
      const id = nextIdRef.current++;
      const fullMsg = { ...msg, id };
      const timer = setTimeout(() => {
        pendingRef.current.delete(id);
        reject(new Error(`Request timeout: ${msg.type}`));
      }, timeoutMs);

      pendingRef.current.set(id, { resolve, reject, timer });
      send(fullMsg);
    });
  }, [send]);

  const subscribe = useCallback((type: string, handler: (msg: WsMessage) => void) => {
    if (!listenersRef.current.has(type)) {
      listenersRef.current.set(type, new Set());
    }
    listenersRef.current.get(type)!.add(handler);
    return () => {
      listenersRef.current.get(type)?.delete(handler);
    };
  }, []);

  const authenticate = useCallback((password: string) => {
    send({ type: "auth", password });
  }, [send]);

  return (
    <WebSocketContext.Provider value={{ status, userId, send, request, subscribe, authenticate }}>
      {children}
    </WebSocketContext.Provider>
  );
}

export function useWs() {
  const ctx = useContext(WebSocketContext);
  if (!ctx) throw new Error("useWs must be used within WebSocketProvider");
  return ctx;
}
