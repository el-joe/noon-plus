"use client";

import { RefreshCw, Volume2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import { joinLiveStream, sendLiveSignal, sendPeerPresence } from "@/lib/api/live";
import { subscribePeerChannel } from "@/lib/realtime";
import { viewerMessageKey } from "./player-state";
import { LiveViewerSession, type HostMessage, type ViewerState } from "./webrtc-session";

const VIEWER_KEY = "nawy_viewer_id";

function viewerId(): string | undefined {
  try {
    return window.localStorage.getItem(VIEWER_KEY) ?? undefined;
  } catch {
    return undefined;
  }
}

/**
 * D18: browser viewer for a live broadcast over native WebRTC (one
 * RTCPeerConnection to the host's studio, receive only). Loaded with
 * `next/dynamic` (`ssr: false`) from the watch page only. States: connecting,
 * playing, tap for sound (autoplay policy), stream full, host not live,
 * connection failed (retry), ended (`onEnded` lets the page refresh).
 */
export default function WebRtcViewer({ market, slug, poster, onEnded }: { market: string; slug: string; poster?: string | null; onEnded: () => void }) {
  const t = useTranslations("live");
  const video = useRef<HTMLVideoElement>(null);
  const session = useRef<LiveViewerSession | null>(null);
  const [state, setState] = useState<ViewerState>("connecting");
  const [needsSound, setNeedsSound] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const ended = useRef(onEnded);
  ended.current = onEnded;

  useEffect(() => {
    let stopped = false;
    const viewer = new LiveViewerSession({
      join: async (resume) => (await joinLiveStream(market, slug, resume)).data,
      signal: (token, message) => sendLiveSignal(market, slug, token, message),
      presence: async (token) => (await sendPeerPresence(market, slug, token, viewerId())).data,
      subscribe: (join, token, onMessage, onDrop) =>
        subscribePeerChannel({ market, channel: join.channel, socket: join.socket, token, onSignal: (payload) => onMessage(payload as HostMessage), onDrop }),
      createPeer: (config) => new RTCPeerConnection(config),
      onState: (next) => {
        if (stopped) return;
        setState(next);
        if (next === "ended") ended.current();
      },
      onStream: (stream) => {
        const el = video.current;
        if (!el || stopped) return;
        if (!stream) {
          el.srcObject = null;
          return;
        }
        if (el.srcObject !== stream) el.srcObject = stream;
        // Try with sound; browsers that block unmuted autoplay get a muted start and a "tap for sound" button.
        el.muted = false;
        el.play().then(
          () => setNeedsSound(false),
          () => {
            el.muted = true;
            setNeedsSound(true);
            void el.play().catch(() => {});
          },
        );
      },
    });
    session.current = viewer;
    void viewer.start();
    const leave = () => viewer.stop("ended", true);
    window.addEventListener("pagehide", leave);
    return () => {
      stopped = true;
      window.removeEventListener("pagehide", leave);
      viewer.stop("ended", true);
      session.current = null;
    };
  }, [market, slug, attempt]);

  const message = viewerMessageKey(state);
  const retryable = state === "failed" || state === "full" || state === "notLive";

  return (
    <div className="relative size-full bg-black" data-testid="webrtc-viewer" data-state={state}>
      <video ref={video} poster={poster ?? undefined} playsInline autoPlay muted className="size-full object-contain" data-testid="webrtc-video" />
      {message ? (
        <div className="absolute inset-x-0 bottom-4 flex flex-col items-center gap-2 px-4 text-center">
          <p className="max-w-md text-body-sm text-white/90" role="status">
            {t(message)}
          </p>
          {retryable ? (
            <button
              type="button"
              onClick={() => setAttempt((n) => n + 1)}
              className="inline-flex h-9 items-center gap-2 rounded-full bg-surface px-4 text-body-sm font-semibold text-ink"
            >
              <RefreshCw className="size-4" aria-hidden />
              {t("player.retry")}
            </button>
          ) : null}
        </div>
      ) : null}
      {needsSound && state === "playing" ? (
        <button
          type="button"
          onClick={() => {
            const el = video.current;
            if (!el) return;
            el.muted = false;
            void el.play().then(() => setNeedsSound(false), () => {});
          }}
          className="absolute end-3 bottom-3 inline-flex h-9 items-center gap-2 rounded-full bg-surface px-3 text-label font-semibold text-ink"
        >
          <Volume2 className="size-4" /> {t("viewer.unmute")}
        </button>
      ) : null}
    </div>
  );
}
