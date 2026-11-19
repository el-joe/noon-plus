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
  return {
    key,
    host: env.NEXT_PUBLIC_REVERB_HOST?.trim() || (typeof window !== "undefined" ? window.location.hostname : "localhost"),
    port: Number.isFinite(port) && port > 0 ? port : scheme === "https" ? 443 : 80,
    scheme,
  };
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
