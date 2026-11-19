"use client";

import type Echo from "laravel-echo";
import { useEffect, useRef, useState } from "react";

/**
 * D16: Reverb client (laravel-echo + pusher-js) for live UIs.
 *
 * - Off unless `NEXT_PUBLIC_REVERB_APP_KEY` is set at build time; then the
 *   pages keep polling exactly as before.
 * - Browser-only and lazy: both packages are `import()`ed on first use, so
 *   pages without a live UI do not ship them.
 * - Private channels are authorised by `POST /api/nawy/{market}/broadcasting/auth`
 *   (the same-origin proxy adds the bearer token from the httpOnly cookie and
 *   forwards to the API's `broadcasting/auth`).
 */

export type RealtimeConfig = {
  key: string;
  host: string;
  port: number;
  scheme: "http" | "https";
};

type ReverbEcho = Echo<"reverb">;

/** The Reverb settings from the build env, or null when realtime is off. */
export function realtimeConfig(env: Record<string, string | undefined> = {
  NEXT_PUBLIC_REVERB_APP_KEY: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
  NEXT_PUBLIC_REVERB_HOST: process.env.NEXT_PUBLIC_REVERB_HOST,
  NEXT_PUBLIC_REVERB_PORT: process.env.NEXT_PUBLIC_REVERB_PORT,
  NEXT_PUBLIC_REVERB_SCHEME: process.env.NEXT_PUBLIC_REVERB_SCHEME,
}): RealtimeConfig | null {
  const key = env.NEXT_PUBLIC_REVERB_APP_KEY?.trim();
  if (!key) return null;
  const scheme = env.NEXT_PUBLIC_REVERB_SCHEME === "http" ? "http" : "https";
  const port = Number(env.NEXT_PUBLIC_REVERB_PORT);
  return tlsOnHttpsPage({
    key,
    host: env.NEXT_PUBLIC_REVERB_HOST?.trim() || (typeof window !== "undefined" ? window.location.hostname : "localhost"),
    port: Number.isFinite(port) && port > 0 ? port : scheme === "https" ? 443 : 80,
    scheme,
  });
}

/**
 * A page served over https cannot open a plain `ws://` socket (mixed
 * content): an http socket config is switched to wss on 443 there, so one
 * build serves both the http and the https local vhosts (deploy/local).
 */
function tlsOnHttpsPage(config: RealtimeConfig): RealtimeConfig {
  if (config.scheme === "https" || typeof window === "undefined" || window.location.protocol !== "https:") return config;
  return { ...config, scheme: "https", port: 443 };
}

/** The proxied channel-auth endpoint for a market. */
export function broadcastAuthEndpoint(market: string): string {
  return `/api/nawy/${encodeURIComponent(market)}/broadcasting/auth`;
}

const clients = new Map<string, Promise<ReverbEcho | null>>();
/** Components subscribed per channel, so one leaving does not unsubscribe the others. */
const subscribers = new Map<string, number>();

/** One Echo client per market (the auth endpoint is per market); null when off or on the server. */
export function getEcho(market: string): Promise<ReverbEcho | null> {
  const config = realtimeConfig();
  if (!config || typeof window === "undefined") return Promise.resolve(null);
  let client = clients.get(market);
  if (!client) {
    client = Promise.all([import("laravel-echo"), import("pusher-js")])
      .then(([{ default: EchoClient }, { default: Pusher }]) =>
        new EchoClient({
          broadcaster: "reverb",
          Pusher,
          key: config.key,
          wsHost: config.host,
          wsPort: config.port,
          wssPort: config.port,
          forceTLS: config.scheme === "https",
          enabledTransports: ["ws", "wss"],
          authEndpoint: broadcastAuthEndpoint(market),
        }) as ReverbEcho,
      )
      .catch(() => null);
    clients.set(market, client);
  }
  return client;
}

export type ChannelOptions = {
  market: string;
  /** Channel name without the `private-` prefix, e.g. `live-stream.eid-sale`. */
  channel: string | null;
  private?: boolean;
  /** Event names as broadcast (`broadcastAs`), without the leading dot. */
  events: Record<string, (payload: unknown) => void>;
};

/**
 * Subscribe to a channel while mounted. Returns true once the subscription
 * succeeded; callers keep (or slow down) their polling while it is false.
 */
export function useRealtimeChannel({ market, channel, private: isPrivate = false, events }: ChannelOptions): boolean {
  const [live, setLive] = useState(false);
  const handlers = useRef(events);
  handlers.current = events;
  const names = Object.keys(events).sort().join("|");

  useEffect(() => {
    setLive(false);
    if (!channel || !realtimeConfig()) return;
    let stopped = false;
    let cleanup: (() => void) | null = null;
    void getEcho(market).then((client) => {
      if (stopped || !client) return;
      const key = `${market}|${isPrivate ? "private-" : ""}${channel}`;
      subscribers.set(key, (subscribers.get(key) ?? 0) + 1);
      const subscription = isPrivate ? client.private(channel) : client.channel(channel);
      const onSubscribed = () => !stopped && setLive(true);
      const onError = () => !stopped && setLive(false);
      subscription.subscribed(onSubscribed).error(onError);
      const listeners = names.split("|").filter(Boolean).map((name) => {
        const callback = (payload: unknown) => handlers.current[name]?.(payload);
        subscription.listen(`.${name}`, callback);
        return [`.${name}`, callback] as const;
      });
      const connection = client.connector.pusher.connection;
      const onState = ({ current }: { current: string }) => {
        if (current !== "connected" && !stopped) setLive(false);
      };
      connection.bind("state_change", onState);
      cleanup = () => {
        connection.unbind("state_change", onState);
        for (const [event, callback] of listeners) subscription.stopListening(event, callback);
        const left = (subscribers.get(key) ?? 1) - 1;
        if (left <= 0) {
          subscribers.delete(key);
          client.leaveChannel(isPrivate ? `private-${channel}` : channel);
        } else {
          subscribers.set(key, left);
        }
      };
    });
    return () => {
      stopped = true;
      cleanup?.();
    };
  }, [market, channel, isPrivate, names]);

  return live;
}

/**
 * D18: the Reverb settings for a WebRTC viewer — the build env when set,
 * else the public socket the API sent with the join (`join.socket`).
 */
export function peerSocketConfig(socket: { key?: string | null; host?: string | null; port?: number | null; scheme?: "http" | "https" } | null | undefined): RealtimeConfig | null {
  const built = realtimeConfig();
  if (built) return built;
  if (!socket?.key) return null;
  const scheme = socket.scheme === "http" ? "http" : "https";
  return tlsOnHttpsPage({
    key: socket.key,
    host: socket.host || (typeof window !== "undefined" ? window.location.hostname : "localhost"),
    port: socket.port && socket.port > 0 ? socket.port : scheme === "https" ? 443 : 80,
    scheme,
  });
}

/**
 * D18: a dedicated socket for one WebRTC viewer's private peer channel. Its
 * authoriser sends the current peer token (renewed by the heartbeat) through
 * the same-origin proxy, so guests can subscribe and a reconnect re-authorises
 * with a fresh token. Resolves once subscribed; rejects on auth / connection
 * failure. `onDrop` fires when the socket goes away afterwards.
 */
export async function subscribePeerChannel(options: {
  market: string;
  channel: string;
  socket: Parameters<typeof peerSocketConfig>[0];
  token: () => string;
  onSignal: (payload: unknown) => void;
  onDrop: () => void;
}): Promise<{ close(): void }> {
  const config = peerSocketConfig(options.socket);
  if (!config || typeof window === "undefined") throw Object.assign(new Error("Realtime is off."), { code: "realtime_off" });
  const [{ default: EchoClient }, { default: Pusher }] = await Promise.all([import("laravel-echo"), import("pusher-js")]);
  const endpoint = broadcastAuthEndpoint(options.market);
  const echo = new EchoClient({
    broadcaster: "reverb",
    Pusher,
    key: config.key,
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === "https",
    enabledTransports: ["ws", "wss"],
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        fetch(endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name, peer_token: options.token() }),
        })
          .then(async (response) => (response.ok ? callback(null, (await response.json()) as { auth: string }) : callback(new Error(`auth ${response.status}`), null)))
          .catch((error: Error) => callback(error, null));
      },
    }),
  }) as ReverbEcho;
  const name = options.channel.replace(/^private-/, "");
  const close = () => {
    try {
      echo.leaveChannel(options.channel);
      echo.disconnect();
    } catch {
      // Already closed.
    }
  };

  return new Promise((resolve, reject) => {
    let subscribed = false;
    const timeout = window.setTimeout(() => {
      if (!subscribed) {
        close();
        reject(Object.assign(new Error("Realtime did not connect."), { code: "realtime_timeout" }));
      }
    }, 15_000);
    echo
      .private(name)
      .subscribed(() => {
        if (subscribed) return;
        subscribed = true;
        window.clearTimeout(timeout);
        resolve({ close });
      })
      .error(() => {
        if (subscribed) {
          options.onDrop();
          return;
        }
        window.clearTimeout(timeout);
        close();
        reject(Object.assign(new Error("Channel refused."), { code: "realtime_refused" }));
      })
      .listen(".signal", (payload: unknown) => options.onSignal(payload));
    echo.connector.pusher.connection.bind("state_change", ({ current }: { current: string }) => {
      if (subscribed && (current === "unavailable" || current === "failed")) options.onDrop();
    });
  });
}
