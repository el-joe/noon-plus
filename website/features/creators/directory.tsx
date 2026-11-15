import clsx from "clsx";
import { Users } from "lucide-react";
import Link from "next/link";
import { ButtonLink } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/misc";
import { Pager } from "@/features/content/pagination";
import { getCreators } from "@/lib/api/creators";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { CreatorCardView } from "./creator-card";

const TABS = ["influencer", "affiliate", "broker"] as const;
const PER_PAGE = 24;

/** Creators directory (Figma 57:2752): hero, type tabs, search, grid, "Earn with Nawy". */
export async function CreatorDirectory({ market, query }: { market: Market; query: Record<string, string | undefined> }) {
  const t = getT(market.locale);
  const type = TABS.find((k) => k === query.type);
  const page = Math.max(1, Number(query.page) || 1);
  const result = await getCreators(market.slug, { type, q: query.q, page, per_page: PER_PAGE });
  const total = Number(result.meta.total) || result.data.length;
  const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));
  const base = `/${market.slug}/creators`;
  const tabHref = (k?: string) => {
    const params = new URLSearchParams();
    if (k) params.set("type", k);
    if (query.q) params.set("q", query.q);
    const qs = params.toString();
    return qs ? `${base}?${qs}` : base;
  };

  return (
    <div className="flex flex-col gap-6">
      <section className="flex flex-col gap-4 rounded-lg bg-ink p-6 text-white md:flex-row md:items-center md:p-8">
        <div className="flex-1">
          <p className="text-caption font-semibold uppercase text-brand">{t("creators.kicker")}</p>
          <h1 className="text-h1 font-bold">{t("creators.heroTitle")}</h1>
          <p className="mt-2 max-w-xl text-body opacity-80">{t("creators.heroBody")}</p>
          <div className="mt-4 flex flex-wrap gap-2">
            <ButtonLink href="#creators-grid">{t("creators.browse")}</ButtonLink>
            <ButtonLink href={`/${market.slug}/join`} variant="outline" className="border-white/40 bg-transparent text-white hover:bg-white/10">
              {t("creators.become")}
            </ButtonLink>
          </div>
        </div>
        <div className="rounded-lg border border-white/20 px-6 py-4 text-center">
          <p className="text-h1 font-bold text-brand">{total}</p>
          <p className="text-caption opacity-80">{t("creators.statCreators")}</p>
        </div>
      </section>

      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line" id="creators-grid">
        <nav className="scrollbar-none flex gap-4 overflow-x-auto">
          {[undefined, ...TABS].map((k) => (
            <Link
              key={k ?? "all"}
              href={tabHref(k)}
              aria-current={k === type ? "page" : undefined}
              className={clsx("shrink-0 border-b-2 pb-2 text-body", k === type ? "border-brand font-semibold" : "border-transparent text-secondary hover:text-ink")}
            >
              {k ? t(`creators.tabs.${k}`) : t("creators.tabs.all")}
            </Link>
          ))}
        </nav>
        <form action={base} className="mb-2 w-full sm:w-64">
          {type ? <input type="hidden" name="type" value={type} /> : null}
          <input type="search" name="q" defaultValue={query.q ?? ""} placeholder={t("creators.searchPlaceholder")} className="h-10 w-full rounded-full border border-line-strong bg-surface px-4 text-body-sm" />
        </form>
      </div>

      {result.data.length === 0 ? (
        <EmptyState icon={<Users className="size-10" />} title={t("creators.empty")} body={t("creators.emptyBody")} />
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {result.data.map((creator) => (
            <CreatorCardView key={creator.slug} market={market} creator={creator} />
          ))}
        </div>
      )}
      <Pager market={market} basePath={base} query={query} page={page} lastPage={lastPage} />

      <section>
        <h2 className="mb-3 text-h3 font-semibold">{t("creators.earnTitle")}</h2>
        <div className="grid gap-3 md:grid-cols-3">
          {TABS.map((k) => (
            <div key={k} className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-4">
              <p className="text-body font-semibold">{t(`creators.type.${k}`)}</p>
              <p className="flex-1 text-body-sm text-secondary">{t(`creators.earn.${k}`)}</p>
              <ButtonLink href={`/${market.slug}/join`} variant="outline" size="sm" className="self-start">
                {t("creators.apply")}
              </ButtonLink>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}
