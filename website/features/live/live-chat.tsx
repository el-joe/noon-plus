"use client";

import { Send } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { getStreamComments, postStreamComment, sendPresence, streamComment, type StreamComment } from "@/lib/api/live";
import { useErrorMessage, useLoginRedirect, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { useRealtimeChannel } from "@/lib/realtime";

const POLL_MS = 5_000;
/** D16: with a Reverb socket open, polling only backs it up. */
const POLL_LIVE_MS = 30_000;
const HEARTBEAT_MS = 30_000;
const VIEWER_KEY = "nawy_viewer_id";

/** A stable anonymous id for the presence heartbeat (guests). */
function viewerId(): string | undefined {
  try {
    let id = window.localStorage.getItem(VIEWER_KEY);
    if (!id) {
      id = crypto.randomUUID().replace(/-/g, "");
      window.localStorage.setItem(VIEWER_KEY, id);
    }
    return id;
  } catch {
    return undefined;
  }
}

/**
 * QC-040: viewers watching now, from the presence heartbeat (every ~30 s
 * while the page is open and the stream is live).
 */
export function useViewerCount(slug: string, live: boolean, initial: number) {
  const market = useMarket();
  const [viewers, setViewers] = useState(initial);
  useEffect(() => {
    if (!live) return;
    let stopped = false;
    const id = viewerId();
    const beat = async () => {
      if (document.visibilityState === "hidden") return;
      try {
        const { data } = await sendPresence(market.slug, slug, id);
        if (!stopped) setViewers(data.viewers);
      } catch {
        // Throttled or offline: keep the last count.
      }
    };
    void beat();
    const timer = window.setInterval(beat, HEARTBEAT_MS);
    return () => {
      stopped = true;
      window.clearInterval(timer);
    };
  }, [market.slug, slug, live]);
  return [viewers, setViewers] as const;
}

function initials(name: string | null): string {
  return (name ?? "?").split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("") || "?";
}

/**
 * Live chat (Figma 100:12627 right rail): visible comments, oldest first,
 * polled with `after_id` while live — pushed over Reverb (`live-stream.{slug}`,
 * D16) when it is configured, with a slow poll as a safety net; signed-in viewers post (10/min). A
 * comment the filter holds is shown only to its author, marked "waiting
 * for review"; hidden comments never come back from the API.
 */
export function LiveChat({ slug, live, onMeta }: { slug: string; live: boolean; onMeta?: (meta: { viewers?: number; likes?: number }) => void }) {
  const t = useTranslations("live.chat");
  const market = useMarket();
  const { isSignedIn } = useSession();
  const toLogin = useLoginRedirect();
  const message = useErrorMessage();
  const [comments, setComments] = useState<StreamComment[]>([]);
  const [held, setHeld] = useState<StreamComment[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const lastId = useRef<number | undefined>(undefined);
  const list = useRef<HTMLOListElement>(null);
  const metaRef = useRef(onMeta);
  metaRef.current = onMeta;

  const append = (incoming: StreamComment[]) =>
    setComments((current) => {
      const seen = new Set(current.map((c) => c.id));
      return [...current, ...incoming.filter((c) => !seen.has(c.id))].slice(-200);
    });

  const socket = useRealtimeChannel({
    market: market.slug,
    channel: live ? `live-stream.${slug}` : null,
    events: {
      "comment.posted": (payload) => {
        const parsed = streamComment.safeParse(payload);
        if (!parsed.success) return;
        append([parsed.data]);
      },
      "likes.updated": (payload) => {
        const likes = (payload as { likes?: unknown } | null)?.likes;
        if (typeof likes === "number") metaRef.current?.({ likes });
      },
    },
  });

  const socketRef = useRef(socket);
  socketRef.current = socket;

  useEffect(() => {
    let stopped = false;
    let lastPoll = 0;
    lastId.current = undefined;
    setComments([]);
    const load = async () => {
      if (lastId.current !== undefined && document.visibilityState === "hidden") return;
      if (lastId.current !== undefined && socketRef.current && Date.now() - lastPoll < POLL_LIVE_MS) return;
      lastPoll = Date.now();
      try {
        const { data, meta } = await getStreamComments(market.slug, slug, lastId.current);
        if (stopped) return;
        if (data.length > 0) {
          lastId.current = Math.max(lastId.current ?? 0, data[data.length - 1].id);
          append(data);
        } else if (lastId.current === undefined) {
          lastId.current = 0;
        }
        metaRef.current?.({ viewers: typeof meta.viewers === "number" ? meta.viewers : undefined, likes: typeof meta.likes === "number" ? meta.likes : undefined });
      } catch {
        // Keep the thread; the next poll retries.
      } finally {
        if (!stopped) setLoaded(true);
      }
    };
    void load();
    if (!live) return () => void (stopped = true);
    const timer = window.setInterval(load, POLL_MS);
    return () => {
      stopped = true;
      window.clearInterval(timer);
    };
  }, [market.slug, slug, live]);

  useEffect(() => {
    const el = list.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [comments.length, held.length]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!isSignedIn) {
      toLogin();
      return;
    }
    const text = body.trim();
    if (!text) return;
    setSending(true);
    setError(null);
    try {
      const { data } = await postStreamComment(market.slug, slug, text);
      setBody("");
      if (data.status === "held") setHeld((h) => [...h, data]);
      else {
        setComments((current) => (current.some((c) => c.id === data.id) ? current : [...current, data]));
      }
    } catch (e) {
      setError(message(e));
    } finally {
      setSending(false);
    }
  };

  return (
    <section className="flex h-[480px] flex-col overflow-hidden rounded-lg border border-line bg-surface lg:h-[560px]" aria-label={t("title")}>
      <h2 className="border-b border-line px-4 py-3 text-h4 font-semibold">{t("title")}</h2>
      <ol ref={list} className="flex flex-1 flex-col gap-3 overflow-y-auto px-4 py-3" aria-live="polite">
        {loaded && comments.length === 0 && held.length === 0 ? <li className="text-body-sm text-secondary">{live ? t("empty") : t("emptyEnded")}</li> : null}
        {comments.map((c) => (
          <CommentRow key={c.id} comment={c} />
        ))}
        {held.map((c) => (
          <CommentRow key={`held-${c.id}`} comment={c} held={t("held")} />
        ))}
      </ol>
      <form onSubmit={submit} className="flex flex-col gap-1.5 border-t border-line p-3">
        <div className="flex items-center gap-2">
          <input
            value={body}
            onChange={(e) => setBody(e.target.value)}
            maxLength={300}
            disabled={!live || sending}
            placeholder={live ? t("placeholder") : t("closed")}
            aria-label={t("placeholder")}
            className="h-10 min-w-0 flex-1 rounded-full border border-line-strong bg-surface px-4 text-body-sm outline-none focus:border-border-focus disabled:bg-subtle"
          />
          <button type="submit" disabled={!live || sending || (isSignedIn && !body.trim())} aria-label={t("send")} className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand disabled:opacity-50">
            <Send className="size-4 rtl:-scale-x-100" />
          </button>
        </div>
        {error ? <p className="text-caption text-danger-text">{error}</p> : null}
        <p className="text-caption text-muted">{isSignedIn ? t("rules") : t("signIn")}</p>
      </form>
    </section>
  );
}

function CommentRow({ comment, held }: { comment: StreamComment; held?: string }) {
  return (
    <li className="flex gap-2.5">
      <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-caption font-semibold text-brand-text" aria-hidden>
        {initials(comment.author)}
      </span>
      <div className="min-w-0">
        <p className="text-body-sm font-semibold">{comment.author}</p>
        <p className="break-words text-body-sm text-secondary">{comment.body}</p>
        {held ? <p className="text-caption text-warning-text">{held}</p> : null}
      </div>
    </li>
  );
}
