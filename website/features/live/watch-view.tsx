"use client";

import { Check, Eye, Heart, Radio, RefreshCw, ShoppingCart } from "lucide-react";
import dynamic from "next/dynamic";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import { ProductImage } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { likeStream, type StreamDetail } from "@/lib/api/live";
import { formatAmount, formatDateTime } from "@/lib/format";
import { errorMessage, useCartMutation } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { useRealtimeChannel } from "@/lib/realtime";
import { LiveChat, useViewerCount } from "./live-chat";
import { useLivePlayer } from "./player-context";
import { playerFallback, usesWebRtc } from "./player-state";

/** D18: the native WebRTC viewer, loaded only on the watch page of a live broadcast. */
const WebRtcViewer = dynamic(() => import("./webrtc-viewer"), { ssr: false });

/** Watch page body (Figma Live stream player 100:12627): player, info, like, "In this live" products. */
export function WatchView({ stream }: { stream: StreamDetail }) {
  const t = useTranslations("live");
  const market = useMarket();
  const locale = useLocale();
  const router = useRouter();
  const { play, position, nowPlaying } = useLivePlayer();
  const video = useRef<HTMLVideoElement>(null);
  const [likes, setLikes] = useState(stream.likes);
  const [liked, setLiked] = useState(false);
  const isLive = stream.status === "live";
  const [viewers, setViewers] = useViewerCount(stream.slug, isLive, stream.viewers);
  const recorded = stream.playback?.type === "recorded" ? stream.playback : null;
  const playable = recorded && (isLive || stream.status === "ended") ? recorded : null;
  // D18: a live broadcast plays over WebRTC straight from the host's studio; the recording (`media_url`) otherwise.
  const webrtc = usesWebRtc({ isLive, playback: stream.playback });

  // D18: go-live / end arrive over Reverb (`status.changed`) when it is configured: reload the stream.
  useRealtimeChannel({ market: market.slug, channel: `live-stream.${stream.slug}`, events: { "status.changed": () => router.refresh() } });

  // Register with the floating player so playback continues after navigating away.
  useEffect(() => {
    if (!playable && !isLive) return;
    play({ slug: stream.slug, title: stream.title ?? "", src: playable?.media_url ?? null, mime: playable?.mime ?? null, poster: stream.thumbnail_url, live: isLive });
  }, [play, stream.slug, stream.title, stream.thumbnail_url, playable, isLive]);

  useEffect(() => {
    const el = video.current;
    if (!el || !playable) return;
    const start = () => {
      const resumeAt = nowPlaying?.slug === stream.slug ? position.current.time : 0;
      const at = resumeAt > 0 ? resumeAt : playable.mode === "synced" ? playable.offset_seconds : 0;
      if (at > 0) el.currentTime = at;
    };
    if (el.readyState >= 1) start();
    else el.addEventListener("loadedmetadata", start, { once: true });
    const save = () => {
      position.current = { time: el.currentTime, paused: el.paused };
    };
    el.addEventListener("timeupdate", save);
    el.addEventListener("pause", save);
    el.addEventListener("play", save);
    return () => {
      save();
      el.removeEventListener("timeupdate", save);
      el.removeEventListener("pause", save);
      el.removeEventListener("play", save);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [playable?.media_url]);

  const like = async () => {
    if (liked) return;
    setLiked(true);
    setLikes((n) => n + 1);
    try {
      setLikes((await likeStream(market.slug, stream.slug)).data.likes);
    } catch {
      // Likes only count while live; keep the optimistic heart.
    }
  };

  const fallback = playerFallback({ isLive, status: stream.status });
  const when = stream.scheduled_at ? formatDateTime(stream.scheduled_at, market.locale, market.country.timeZone) : "";

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
      <section className="flex flex-col gap-3">
        <div className="relative aspect-video overflow-hidden rounded-lg bg-ink text-white">
          {webrtc ? (
            <WebRtcViewer market={market.slug} slug={stream.slug} poster={stream.thumbnail_url} onEnded={() => router.refresh()} />
          ) : playable ? (
            <video ref={video} src={playable.media_url} poster={stream.thumbnail_url ?? undefined} controls playsInline autoPlay className="size-full bg-black object-contain">
              {playable.mime ? <source src={playable.media_url} type={playable.mime} /> : null}
            </video>
          ) : (
            <div className="relative flex size-full flex-col items-center justify-center gap-3 p-6 text-center">
              {stream.thumbnail_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={stream.thumbnail_url} alt="" className="absolute inset-0 size-full object-cover opacity-40" />
              ) : null}
              <Radio className="relative size-12" />
              <p className="relative max-w-md text-body" role="status">
                {fallback === "unavailable" ? t("player.unavailable") : fallback === "ended" ? t("ended") : t("notStarted", { date: when })}
              </p>
              {fallback === "unavailable" ? (
                <button
                  type="button"
                  onClick={() => router.refresh()}
                  className="relative inline-flex h-9 items-center gap-2 rounded-full bg-surface px-4 text-body-sm font-semibold text-ink"
                >
                  <RefreshCw className="size-4" aria-hidden />
                  {t("player.retry")}
                </button>
              ) : null}
            </div>
          )}
          {isLive ? (
            <span className="absolute start-3 top-3 flex items-center gap-2">
              <span className="rounded-xs bg-danger px-2 py-0.5 text-caption font-semibold">{t("status.live")}</span>
              <span className="flex items-center gap-1 rounded-full bg-surface px-2 py-0.5 text-caption font-medium text-ink">
                <Eye className="size-3.5" /> {t("watching", { count: viewers })}
              </span>
            </span>
          ) : null}
        </div>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-h2 font-bold">{stream.title}</h1>
            {!isLive && stream.status !== "ended" && when ? <p className="text-body-sm text-secondary">{t("startsAt", { date: when })}</p> : null}
          </div>
          <button
            type="button"
            onClick={like}
            disabled={!isLive || liked}
            aria-pressed={liked}
            className="inline-flex h-10 items-center gap-2 rounded-full border border-line-strong bg-surface px-4 text-body font-semibold disabled:opacity-70"
          >
            <Heart className={liked ? "size-4 fill-danger text-danger" : "size-4"} />
            {t("likes", { count: likes })}
          </button>
        </div>
        {stream.description ? <p className="whitespace-pre-line text-body text-secondary" lang={locale}>{stream.description}</p> : null}
        <div className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-3">
          <h2 className="text-h4 font-semibold">{t("inThisLive", { count: stream.products.length })}</h2>
          {stream.products.length === 0 ? <p className="text-body-sm text-secondary">{t("noProducts")}</p> : null}
          <ul className="grid gap-x-4 divide-line sm:grid-cols-2">
            {stream.products.map((product) => (
              <ProductRow key={product.listing_id} product={product} />
            ))}
          </ul>
        </div>
      </section>
      <aside className="self-start lg:sticky lg:top-32">
        <LiveChat
          slug={stream.slug}
          live={isLive}
          onMeta={(meta) => {
            if (meta.viewers !== undefined && isLive) setViewers(meta.viewers);
            if (meta.likes !== undefined) setLikes((n) => Math.max(n, meta.likes!));
          }}
        />
      </aside>
    </div>
  );
}

function ProductRow({ product }: { product: StreamDetail["products"][number] }) {
  const t = useTranslations("live");
  const href = useHref();
  const add = useCartMutation((m, listingId: number) => api.setCartItem(m, listingId, 1));
  const [error, setError] = useState<string | null>(null);
  return (
    <li className="flex items-center gap-3 border-b border-line py-2">
      <div className="size-12 shrink-0 overflow-hidden rounded-md">
        <ProductImage src={product.image?.url} alt={product.image?.alt || product.title || ""} />
      </div>
      <div className="min-w-0 flex-1">
        {product.slug ? (
          <Link href={href(`/products/${product.slug}`)} className="line-clamp-2 text-body-sm hover:underline">
            {product.title}
          </Link>
        ) : (
          <span className="line-clamp-2 text-body-sm">{product.title}</span>
        )}
        {product.price ? (
          <p className="text-body-sm font-semibold text-danger-text">
            {product.price.currency} {formatAmount(product.price.amount)}
          </p>
        ) : null}
        {error ? <p className="text-caption text-danger-text">{error}</p> : null}
      </div>
      <button
        type="button"
        onClick={() => {
          setError(null);
          add.mutate(product.listing_id, { onError: (e) => setError(errorMessage(e, t("add"))) });
        }}
        disabled={add.isPending}
        className="inline-flex h-8 shrink-0 items-center gap-1 rounded-md bg-brand px-3 text-label font-semibold disabled:opacity-60"
      >
        {add.isSuccess ? <Check className="size-4" /> : <ShoppingCart className="size-4" />}
        {add.isSuccess ? t("added") : t("add")}
      </button>
    </li>
  );
}
