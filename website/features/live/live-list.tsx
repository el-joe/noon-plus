import clsx from "clsx";
import { CalendarClock, Radio } from "lucide-react";
import Link from "next/link";
import { EmptyState } from "@/components/ui/misc";
import { getStreams, type StreamSummary } from "@/lib/api/live";
import { formatDateTime } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { StreamCard } from "./stream-card";

type Tab = "all" | "live" | "upcoming" | "replays";

/** Nawy TV (Figma 59:4518): live hero, upcoming lives, replays — filtered by tab. */
export async function LiveList({ market, tab }: { market: Market; tab: Tab }) {
  const t = getT(market.locale);
  const streams = (await getStreams(market.slug)).data;
  const live = streams.filter((s) => s.status === "live");
  const upcoming = streams.filter((s) => s.status === "scheduled" || s.status === "idle");
  const replays = streams.filter((s) => s.status === "ended");
  const hero = live[0] ?? null;
  const base = `/${market.slug}/live`;
  const tabs: { key: Tab; label: string }[] = [
    { key: "all", label: t("live.tabs.all") },
    { key: "live", label: t("live.tabs.live", { count: live.length }) },
    { key: "upcoming", label: t("live.tabs.upcoming") },
    { key: "replays", label: t("live.tabs.replays") },
  ];

  return (
    <div className="flex flex-col gap-6">
      <header>
        <h1 className="text-h1 font-bold">{t("live.title")}</h1>
        <p className="text-body text-secondary">{t("live.subtitle")}</p>
      </header>

      {hero && tab === "all" ? (
        <Link href={`${base}/${encodeURIComponent(hero.slug)}`} className="relative flex aspect-[16/7] min-h-56 items-end overflow-hidden rounded-lg bg-ink p-5 text-white">
          {hero.thumbnail_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={hero.thumbnail_url} alt="" className="absolute inset-0 size-full object-cover opacity-70" />
          ) : null}
          <span className="absolute start-4 top-4 flex items-center gap-1 rounded-xs bg-danger px-2 py-0.5 text-caption font-semibold">
            <Radio className="size-3.5" />
            {t("live.status.live")}
          </span>
          <span className="relative text-h2 font-bold">{hero.title}</span>
        </Link>
      ) : null}

      <nav className="scrollbar-none flex gap-4 overflow-x-auto border-b border-line" aria-label={t("live.title")}>
        {tabs.map((item) => (
          <Link
            key={item.key}
            href={item.key === "all" ? base : `${base}?tab=${item.key}`}
            aria-current={item.key === tab ? "page" : undefined}
            className={clsx("shrink-0 border-b-2 pb-2 text-body", item.key === tab ? "border-brand font-semibold" : "border-transparent text-secondary hover:text-ink")}
          >
            {item.label}
          </Link>
        ))}
      </nav>

      {streams.length === 0 ? (
        <EmptyState icon={<Radio className="size-10" />} title={t("live.empty")} body={t("live.emptyBody")} />
      ) : null}

      {(tab === "all" || tab === "live") && live.length > 0 ? <Section market={market} title={t("live.liveNow")} streams={live} /> : null}
      {(tab === "all" || tab === "upcoming") && upcoming.length > 0 ? <Upcoming market={market} streams={upcoming} /> : null}
      {(tab === "all" || tab === "replays") && replays.length > 0 ? <Section market={market} title={t("live.replays")} streams={replays} /> : null}
      {tab !== "all" && streams.length > 0 && (tab === "live" ? live : tab === "upcoming" ? upcoming : replays).length === 0 ? (
        <EmptyState icon={<Radio className="size-10" />} title={t("live.empty")} body={t("live.emptyBody")} />
      ) : null}
    </div>
  );
}

function Section({ market, title, streams }: { market: Market; title: string; streams: StreamSummary[] }) {
  return (
    <section>
      <h2 className="mb-3 text-h3 font-semibold">{title}</h2>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {streams.map((s) => (
          <StreamCard key={s.slug} market={market} stream={s} />
        ))}
      </div>
    </section>
  );
}

function Upcoming({ market, streams }: { market: Market; streams: StreamSummary[] }) {
  const t = getT(market.locale);
  return (
    <section>
      <h2 className="mb-3 text-h3 font-semibold">{t("live.upcoming")}</h2>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {streams.map((s) => (
          <Link key={s.slug} href={`/${market.slug}/live/${encodeURIComponent(s.slug)}`} className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-4 hover:shadow-sm">
            <span className="self-start rounded-full bg-info-subtle px-2 py-0.5 text-caption font-semibold uppercase text-link">{t(`live.status.${s.status === "idle" ? "idle" : "scheduled"}`)}</span>
            <span className="text-body font-semibold">{s.title}</span>
            {s.scheduled_at ? (
              <span className="flex items-center gap-1 text-body-sm text-secondary">
                <CalendarClock className="size-4" />
                {formatDateTime(s.scheduled_at, market.locale, market.country.timeZone)}
              </span>
            ) : null}
          </Link>
        ))}
      </div>
    </section>
  );
}
