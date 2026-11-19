import clsx from "clsx";
import { CalendarClock, Eye, Heart, Play, Radio } from "lucide-react";
import Link from "next/link";
import type { StreamSummary } from "@/lib/api/live";
import { formatDateTime } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

export function streamStatusKey(status: string): "live" | "scheduled" | "idle" | "ended" {
  return status === "live" || status === "scheduled" || status === "idle" || status === "ended" ? status : "scheduled";
}

/** Vertical video card (Figma Nawy TV 59:4518 "Shoppable videos"). */
export function StreamCard({ market, stream, className }: { market: Market; stream: StreamSummary; className?: string }) {
  const t = getT(market.locale);
  const status = streamStatusKey(stream.status);
  return (
    <Link href={`/${market.slug}/live/${encodeURIComponent(stream.slug)}`} className={clsx("group flex flex-col gap-1.5", className)}>
      <div className="relative aspect-[9/14] overflow-hidden rounded-lg bg-ink text-white">
        {stream.thumbnail_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={stream.thumbnail_url} alt="" loading="lazy" className="size-full object-cover transition group-hover:scale-105" />
        ) : (
          <div className="flex size-full items-center justify-center">
            <Radio className="size-10 opacity-60" />
          </div>
        )}
        <span className={clsx("absolute start-2 top-2 rounded-xs px-1.5 py-0.5 text-caption font-semibold", status === "live" ? "bg-danger text-white" : "bg-surface/90 text-ink")}>
          {t(`live.status.${status}`)}
        </span>
        <span className="absolute inset-x-2 bottom-2 flex items-center justify-between text-caption">
          <span className="flex items-center gap-1">
            <Heart className="size-3.5" />
            {stream.likes}
            {status === "live" ? (
              <>
                <Eye className="ms-2 size-3.5" aria-hidden />
                <span aria-label={t("live.watching", { count: stream.viewers })}>{stream.viewers}</span>
              </>
            ) : null}
          </span>
          <Play className="size-5 rounded-full bg-black/40 p-1" />
        </span>
      </div>
      <span className="line-clamp-2 text-body-sm font-semibold">{stream.title}</span>
      {status !== "live" && status !== "ended" && stream.scheduled_at ? (
        <span className="flex items-center gap-1 text-caption text-secondary">
          <CalendarClock className="size-3.5" />
          {formatDateTime(stream.scheduled_at, market.locale, market.country.timeZone)}
        </span>
      ) : null}
    </Link>
  );
}
