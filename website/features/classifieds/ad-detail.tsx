import { ChevronRight, Eye, Home, MapPin } from "lucide-react";
import Link from "next/link";
import { Badge, SectionHeader } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import type { AdDetail } from "@/lib/api/classifieds";
import { formatDay, humanize } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { AdTitleActions, FinancingCard } from "./ad-actions";
import { AdGrid, AdPrice, AdStatusChip } from "./ad-card";
import { ContactPanel } from "./contact-panel";
import { findCategory, loadClassifiedCategories } from "./data";
import { AdGallery } from "./gallery";
import { propertyFact } from "@/features/real-estate/shared";

/** Verticals that show the indicative financing card (Figma 55:1477). */
const FINANCED_VERTICALS = new Set(["motors", "property"]);

/** `/classified/find/[number]` — ad detail (Figma Classified ad detail 55:1477). */
export async function ClassifiedAdDetail({ market, ad }: { market: Market; ad: AdDetail }) {
  const t = getT(market.locale);
  const base = `/${market.slug}/classified`;
  const { tree, propertySlugs } = await loadClassifiedCategories(market.slug);
  const trail = findCategory(tree, ad.category ?? undefined)?.trail ?? [];
  const attributes = ad.category
    ? await api
        .getCategoryAttributes(market.slug, ad.category)
        .then((r) => r.data.attributes)
        .catch(() => [])
    : [];
  const labels = new Map(attributes.map((a) => [a.key, { label: a.label ?? humanize(a.key), unit: a.unit, options: a.options }]));
  const specs = Object.entries(ad.specs ?? {})
    .map(([key, value]) => {
      const meta = labels.get(key);
      const values = Array.isArray(value) ? value : [value];
      const text = values
        .map((v) => {
          const option = meta?.options.find((o) => api.optionValue(o) === String(v));
          return option ? api.optionLabel(option, market.locale) : api.specText(v);
        })
        .join(", ");
      return [meta?.label ?? humanize(key), meta?.unit && text ? `${text} ${meta.unit}` : text] as const;
    })
    .filter(([, v]) => v !== "");
  const property = Object.entries(ad.property ?? {}).filter(([, v]) => v !== null && v !== "" && !(Array.isArray(v) && v.length === 0));
  const sellerName = ad.seller.shop?.name ?? ad.seller.broker?.name ?? t("classifieds.sellerType.individual");

  return (
    <div className="flex flex-col gap-6">
      <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label={t("common.breadcrumb")}>
        <Link href={`/${market.slug}`} aria-label={t("common.home")}>
          <Home className="size-3.5" />
        </Link>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <Link href={base} className="hover:text-ink">
          {t("classifieds.title")}
        </Link>
        {trail.map((c) => (
          <span key={c.slug} className="flex items-center gap-1.5">
            <ChevronRight className="size-3.5 rtl:rotate-180" />
            <Link href={`${base}/search?category=${c.slug}`} className="hover:text-ink">
              {c.name}
            </Link>
          </span>
        ))}
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <span className="line-clamp-1 text-ink">{ad.title}</span>
      </nav>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex min-w-0 flex-col gap-4">
          <AdGallery media={ad.media} title={ad.title} />

          <section className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-5">
            <div className="flex flex-wrap items-center gap-2">
              {ad.is_featured ? <Badge tone="brand">{t("classifieds.featured")}</Badge> : null}
              {ad.kind === "wanted" ? <Badge tone="warning">{t("classifieds.wanted")}</Badge> : null}
              {ad.status !== "active" ? <AdStatusChip status={ad.status} /> : null}
            </div>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <h1 className="text-h2 font-bold">{ad.title}</h1>
              {/* QC-061: Save (n) · Share · Compare */}
              <AdTitleActions number={ad.number} title={ad.title} saves={ad.saves ?? 0} live={ad.status === "active"} />
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <AdPrice ad={ad} className="text-h3" />
              {ad.is_negotiable ? <Badge tone="success">{t("classifieds.negotiable")}</Badge> : null}
            </div>
            <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-body-sm text-secondary">
              <span className="flex items-center gap-1">
                <MapPin className="size-3.5" />
                {[ad.city, ad.area].filter(Boolean).join(" · ") || "—"}
              </span>
              {ad.posted_at ? <span>{t("classifieds.posted", { date: formatDay(ad.posted_at, market.locale) })}</span> : null}
              <span>{t("classifieds.adId", { number: ad.number })}</span>
              {typeof ad.views === "number" ? (
                <span className="flex items-center gap-1">
                  <Eye className="size-3.5" />
                  {t("classifieds.views", { count: ad.views })}
                </span>
              ) : null}
            </p>
          </section>

          {specs.length > 0 ? (
            <section className="rounded-lg border border-line bg-surface p-5">
              <h2 className="mb-3 text-h4 font-semibold">{t("classifieds.details")}</h2>
              <dl className="grid gap-x-8 sm:grid-cols-2">
                {specs.map(([label, value]) => (
                  <div key={label} className="flex justify-between gap-4 border-b border-line py-2 text-body-sm">
                    <dt className="text-secondary">{label}</dt>
                    <dd className="text-end font-medium">{value}</dd>
                  </div>
                ))}
              </dl>
            </section>
          ) : null}

          {property.length > 0 ? (
            <section className="rounded-lg border border-line bg-surface p-5">
              <h2 className="mb-3 text-h4 font-semibold">{t("classifieds.propertyDetails")}</h2>
              <dl className="grid gap-x-8 sm:grid-cols-2">
                {property.map(([key, value]) => {
                  const [label, text] = propertyFact(t, market.locale, key, value, api.specText);
                  return (
                    <div key={key} className="flex justify-between gap-4 border-b border-line py-2 text-body-sm">
                      <dt className="text-secondary">{label}</dt>
                      <dd className="text-end font-medium">{text}</dd>
                    </div>
                  );
                })}
              </dl>
            </section>
          ) : null}

          {ad.description ? (
            <section className="rounded-lg border border-line bg-surface p-5">
              <h2 className="mb-3 text-h4 font-semibold">{t("classifieds.description")}</h2>
              <p className="whitespace-pre-line text-body">{ad.description}</p>
            </section>
          ) : null}

          {ad.location || ad.city ? (
            <section className="rounded-lg border border-line bg-surface p-5">
              <h2 className="mb-3 text-h4 font-semibold">{t("classifieds.location")}</h2>
              <div className="flex aspect-[3/1] w-full flex-col items-center justify-center gap-2 rounded-md bg-info-subtle text-body-sm text-secondary">
                <MapPin className="size-6 text-danger-text" />
                <span>{[ad.area, ad.city].filter(Boolean).join(", ")}</span>
                {ad.location ? (
                  <a
                    href={`https://www.openstreetmap.org/?mlat=${ad.location.lat}&mlon=${ad.location.lng}#map=15/${ad.location.lat}/${ad.location.lng}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-link"
                  >
                    {t("classifieds.openMap")}
                  </a>
                ) : null}
              </div>
            </section>
          ) : null}
        </div>

        <aside className="lg:sticky lg:top-32">
          <ContactPanel ad={ad} sellerName={sellerName}>
            {ad.price && ad.kind !== "wanted" && FINANCED_VERTICALS.has(ad.vertical ?? "") ? <FinancingCard price={ad.price} /> : null}
          </ContactPanel>
        </aside>
      </div>

      {ad.similar.length > 0 ? (
        <section>
          <SectionHeader
            title={t("classifieds.similar")}
            action={
              ad.category ? (
                <Link href={`${base}/search?category=${ad.category}`} className="text-body-sm font-semibold text-link">
                  {t("common.viewAll")}
                </Link>
              ) : undefined
            }
          />
          <AdGrid ads={ad.similar.slice(0, 5)} propertySlugs={propertySlugs} />
        </section>
      ) : null}
    </div>
  );
}
