"use client";

import clsx from "clsx";
import { Maximize2, Minimize2, Radio, X } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useRef } from "react";
import { useHref } from "@/lib/market-context";
import { useLivePlayer } from "./player-context";

/**
 * Floating mini-player: expanded (video + title), collapsed (pill) or
 * dismissed (hidden until the next watch page). Hidden on the stream's own
 * watch page, which shows the full player.
 */
export function MiniPlayer() {
  const t = useTranslations("live.mini");
  const tl = useTranslations("live");
  const href = useHref();
  const pathname = usePathname();
  const { nowPlaying, mode, setMode, position } = useLivePlayer();
  const video = useRef<HTMLVideoElement>(null);

  const watchPath = nowPlaying ? href(`/live/${encodeURIComponent(nowPlaying.slug)}`) : null;
  const visible = !!nowPlaying && mode !== "dismissed" && pathname !== watchPath;

  useEffect(() => {
    const el = video.current;
    if (!visible || !el) return;
    const resume = () => {
      if (position.current.time > 0 && Math.abs(el.currentTime - position.current.time) > 1) el.currentTime = position.current.time;
      if (!position.current.paused) void el.play().catch(() => undefined);
    };
    if (el.readyState >= 1) resume();
    else el.addEventListener("loadedmetadata", resume, { once: true });
    const save = () => {
      position.current = { time: el.currentTime, paused: el.paused };
    };
    el.addEventListener("timeupdate", save);
    el.addEventListener("pause", save);
    return () => {
      save();
      el.removeEventListener("timeupdate", save);
      el.removeEventListener("pause", save);
    };
  }, [visible, mode, position]);

  if (!visible || !nowPlaying || !watchPath) return null;

  if (mode === "collapsed") {
    return (
      <div className="fixed bottom-4 end-4 z-40 flex items-center gap-2 rounded-full bg-ink py-1.5 ps-3 pe-1.5 text-white shadow-lg" role="region" aria-label={t("label")}>
        {nowPlaying.live ? <span className="size-2 animate-pulse rounded-full bg-danger" aria-hidden /> : <Radio className="size-4" />}
        <Link href={watchPath} className="max-w-48 truncate text-body-sm font-medium">
          {nowPlaying.title}
        </Link>
        <button type="button" onClick={() => setMode("expanded")} aria-label={t("expand")} className="rounded-full p-1.5 hover:bg-white/10">
          <Maximize2 className="size-4" />
        </button>
        <button type="button" onClick={() => setMode("dismissed")} aria-label={t("close")} className="rounded-full p-1.5 hover:bg-white/10">
          <X className="size-4" />
        </button>
      </div>
    );
  }

  return (
    <div className="fixed bottom-4 end-4 z-40 w-72 overflow-hidden rounded-lg bg-ink text-white shadow-lg sm:w-80" role="region" aria-label={t("label")}>
      <div className="relative aspect-video bg-black">
        {nowPlaying.src ? (
          <video ref={video} src={nowPlaying.src} poster={nowPlaying.poster ?? undefined} playsInline controls muted={false} className="size-full object-contain" />
        ) : (
          <Link href={watchPath} className="flex size-full flex-col items-center justify-center gap-2 p-3 text-center text-body-sm">
            <Radio className="size-8" />
            {tl("playerSoon")}
          </Link>
        )}
        {nowPlaying.live ? <span className="absolute start-2 top-2 rounded-xs bg-danger px-1.5 py-0.5 text-caption font-semibold">{tl("status.live")}</span> : null}
      </div>
      <div className="flex items-center gap-2 p-2">
        <Link href={watchPath} className={clsx("min-w-0 flex-1 truncate text-body-sm font-medium hover:underline")} title={t("open")}>
          {nowPlaying.title}
        </Link>
        <button type="button" onClick={() => setMode("collapsed")} aria-label={t("collapse")} className="rounded-md p-1.5 hover:bg-white/10">
          <Minimize2 className="size-4" />
        </button>
        <button type="button" onClick={() => setMode("dismissed")} aria-label={t("close")} className="rounded-md p-1.5 hover:bg-white/10">
          <X className="size-4" />
        </button>
      </div>
    </div>
  );
}
