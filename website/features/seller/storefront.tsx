import clsx from "clsx";
import { BadgeCheck, CalendarDays, MapPin, RotateCcw, ShieldCheck, Store, Truck } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";
import { Alert, Badge, EmptyState } from "@/components/ui/misc";
import { Stars } from "@/components/ui/rating";
import { ProductListing, type ListingQuery } from "@/features/listing/product-listing";
import { ApiError } from "@/lib/api/client";
import { getSellerReviews, type SellerProfile, type SellerReviewPage } from "@/lib/api/sellers";
import { formatDay, formatMoney } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

export type StoreTab = "products" | "reviews";

const BADGE_TONE: Record<string, "success" | "brand" | "info"> = { top_rated: "success", fulfilled_by_nawy: "brand", new_seller: "info" };

/** Store header (QC-008): banner, logo, name, badges, city, joined date, rating, product count and follow. */
export function SellerHeader({ market, seller, follow }: { market: Market; seller: SellerProfile; follow: ReactNode }) {
  const t = getT(market.locale);
  const average = seller.rating.average == null ? null : Number(seller.rating.average);
  return (
    <header className="overflow-hidden rounded-lg border border-line bg-surface">
      {seller.banner ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={seller.banner} alt="" className="h-32 w-full object-cover md:h-44" />
      ) : (
        <div className="h-20 bg-brand-subtle md:h-24" aria-hidden />
      )}
      <div className="flex flex-wrap items-end gap-4 px-5 pb-5">
        <span className="-mt-10 flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border-4 border-surface bg-white text-h3 font-bold shadow-sm">
          {seller.logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={seller.logo} alt={seller.name} className="size-full object-contain p-1.5" />
          ) : (
            seller.name.slice(0, 2).toUpperCase()
          )}
        </span>
        <div className="min-w-0 flex-1 pt-3">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-h1 font-bold">{seller.name}</h1>
            {seller.badges.map((badge) => (
              <Badge key={badge.key} tone={BADGE_TONE[badge.key] ?? "neutral"}>
                {badge.key === "top_rated" ? <BadgeCheck className="size-3.5" /> : badge.key === "fulfilled_by_nawy" ? <Truck className="size-3.5" /> : null}
                {badge.label}
              </Badge>
            ))}
          </div>
          <p className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-body-sm text-secondary">
            <span className="inline-flex items-center gap-1.5">
              <Stars value={average} />
              {average != null ? <span className="font-semibold text-ink">{average.toFixed(1)}</span> : null}
              <span>{t("storefront.seller.ratingCount", { count: seller.rating.count })}</span>
            </span>
            {seller.rating.positive_percent != null ? <span>{t("storefront.seller.positive", { percent: seller.rating.positive_percent })}</span> : null}
            <span>{t("storefront.seller.products", { count: seller.product_count })}</span>
            {seller.city ? (
              <span className="inline-flex items-center gap-1">
                <MapPin className="size-3.5" />
                {seller.city}
              </span>
            ) : null}
            {seller.joined_at ? (
              <span className="inline-flex items-center gap-1">
                <CalendarDays className="size-3.5" />
                {t("storefront.seller.joined", { date: new Intl.DateTimeFormat(market.locale === "ar" ? "ar-u-nu-latn" : "en-GB", { month: "long", year: "numeric" }).format(new Date(seller.joined_at)) })}
              </span>
            ) : null}
          </p>
        </div>
        {follow}
      </div>
    </header>
  );
}

/** Returns, shipping and warranty policies. */
export function SellerPolicies({ market, seller }: { market: Market; seller: SellerProfile }) {
  const t = getT(market.locale);
  const { returns, shipping, warranty } = seller.policies;
  const cards = [
    returns
      ? {
          icon: RotateCcw,
          title: t("storefront.seller.policies.returns"),
          lines: [returns.window_days != null ? t("storefront.seller.policies.returnsWindow", { days: returns.window_days }) : null, returns.note],
        }
      : null,
    shipping
      ? {
          icon: Truck,
          title: t("storefront.seller.policies.shipping"),
          lines: [
            shipping.fulfilled_by_nawy ? t("storefront.seller.policies.fbn") : null,
            shipping.fee ? t("storefront.seller.policies.shippingFee", { fee: formatMoney(shipping.fee) }) : null,
            shipping.free_over ? t("storefront.seller.policies.freeOver", { amount: formatMoney(shipping.free_over) }) : null,
          ],
        }
      : null,
    warranty?.note ? { icon: ShieldCheck, title: t("storefront.seller.policies.warranty"), lines: [warranty.note] } : null,
  ].filter((c) => c !== null);
  if (cards.length === 0) return null;
  return (
    <section aria-labelledby="store-policies" className="flex flex-col gap-3">
      <h2 id="store-policies" className="sr-only">
        {t("storefront.seller.policies.title")}
      </h2>
      <div className="grid gap-3 md:grid-cols-3">
        {cards.map(({ icon: Icon, title, lines }) => (
          <div key={title} className="flex gap-3 rounded-lg border border-line bg-surface p-4">
            <Icon className="mt-0.5 size-5 shrink-0 text-brand-text" />
            <div className="min-w-0">
              <h3 className="text-body font-semibold">{title}</h3>
              {lines.filter(Boolean).map((line) => (
                <p key={line} className="text-body-sm text-secondary">
                  {line}
                </p>
              ))}
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

export function SellerTabs({ market, seller, tab, basePath }: { market: Market; seller: SellerProfile; tab: StoreTab; basePath: string }) {
  const t = getT(market.locale);
  const tabs: { key: StoreTab; label: string; href: string }[] = [
    { key: "products", label: t("storefront.seller.tabs.products"), href: basePath },
    { key: "reviews", label: t("storefront.seller.tabs.reviews", { count: seller.rating.count }), href: `${basePath}?tab=reviews` },
  ];
  return (
    <nav className="flex gap-6 border-b border-line" aria-label={seller.name}>
      {tabs.map((item) => (
        <Link
          key={item.key}
          href={item.href}
          aria-current={tab === item.key ? "page" : undefined}
          className={clsx("-mb-px border-b-2 px-1 pb-2.5 text-body", tab === item.key ? "border-ink font-semibold" : "border-transparent text-secondary hover:text-ink")}
        >
          {item.label}
        </Link>
      ))}
    </nav>
  );
}

export function SellerProducts({ market, seller, query, basePath }: { market: Market; seller: SellerProfile; query: ListingQuery; basePath: string }) {
  return <ProductListing market={market} query={query} basePath={basePath} source={{ kind: "seller", slug: seller.slug }} />;
}

/** Reviews tab: summary with the star histogram, then the published reviews (`sellers/{slug}/reviews`). */
export async function SellerReviews({ market, seller, page, basePath }: { market: Market; seller: SellerProfile; page: number; basePath: string }) {
  const t = getT(market.locale);
  let data: SellerReviewPage | null = null;
  let meta: Record<string, unknown> = {};
  let failed = false;
  try {
    const result = await getSellerReviews(market.slug, seller.slug, { page, per_page: 10 });
    data = result.data;
    meta = result.meta;
  } catch (error) {
    failed = !(error instanceof ApiError && error.status === 404);
  }
  if (failed) return <Alert tone="danger">{t("common.unreachable")}</Alert>;
  const summary = data?.summary ?? { average: seller.rating.average, count: seller.rating.count, histogram: seller.rating.histogram };
  const reviews = data?.reviews ?? [];
  const last = Number(meta.last_page ?? 1);
  const current = Number(meta.page ?? page);
  const average = summary.average == null ? null : Number(summary.average);
  const pageHref = (p: number) => `${basePath}?tab=reviews${p > 1 ? `&page=${p}` : ""}`;

  return (
    <div className="grid items-start gap-6 lg:grid-cols-[300px_1fr]">
      <section className="flex flex-col gap-3 rounded-lg border border-line bg-surface p-5">
        <h2 className="text-h3 font-semibold">{t("storefront.seller.reviews.title")}</h2>
        <div className="flex items-center gap-3">
          <span className="text-display font-bold">{average != null ? average.toFixed(1) : "–"}</span>
          <div className="flex flex-col gap-0.5">
            <Stars value={average} size="md" />
            <span className="text-body-sm text-secondary">{t("storefront.seller.ratingCount", { count: summary.count })}</span>
          </div>
        </div>
        {seller.rating.positive_percent != null ? <p className="text-body-sm text-success-text">{t("storefront.seller.positive", { percent: seller.rating.positive_percent })}</p> : null}
        <ul className="flex flex-col gap-1.5">
          {[5, 4, 3, 2, 1].map((stars) => {
            const count = Number(summary.histogram[String(stars)] ?? 0);
            const pct = summary.count > 0 ? Math.round((count / summary.count) * 100) : 0;
            return (
              <li key={stars} className="flex items-center gap-2 text-body-sm">
                <span className="w-14 shrink-0 text-secondary">{t("storefront.seller.reviews.stars", { stars })}</span>
                <span className="h-2 flex-1 overflow-hidden rounded-full bg-subtle">
                  <span className="block h-full rounded-full bg-brand" style={{ width: `${pct}%` }} />
                </span>
                <span className="w-8 shrink-0 text-end text-caption text-muted tabular-nums">{count}</span>
              </li>
            );
          })}
        </ul>
      </section>
      <section className="min-w-0">
        {reviews.length === 0 ? (
          <EmptyState icon={<Store className="size-10" />} title={t("storefront.seller.reviews.empty")} body={t("storefront.seller.reviews.emptyHint")} />
        ) : (
          <ul className="divide-y divide-line rounded-lg border border-line bg-surface px-5">
            {reviews.map((review) => (
              <li key={review.id} className="flex flex-col gap-2 py-4">
                <div className="flex flex-wrap items-center gap-2">
                  <Stars value={review.rating} />
                  {review.title ? <h3 className="text-body font-semibold">{review.title}</h3> : null}
                </div>
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm text-secondary">
                  {review.author ? <span className="font-medium text-ink">{review.author}</span> : null}
                  <span>{formatDay(review.created_at, market.locale)}</span>
                  {review.verified_purchase ? (
                    <span className="inline-flex items-center gap-1 text-success-text">
                      <BadgeCheck className="size-3.5" />
                      {t("storefront.seller.reviews.verified")}
                    </span>
                  ) : null}
                </p>
                {review.body ? <p className="whitespace-pre-line text-body">{review.body}</p> : null}
                {review.photos.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {review.photos.map((photo) => (
                      <a key={photo.id} href={photo.url} target="_blank" rel="noreferrer" className="size-16 overflow-hidden rounded-md border border-line">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={photo.url} alt="" loading="lazy" className="size-full object-cover" />
                      </a>
                    ))}
                  </div>
                ) : null}
                {review.seller_reply ? (
                  <div className="ms-4 flex flex-col gap-1 rounded-md border-s-2 border-brand bg-subtle p-3">
                    <p className="flex items-center gap-1.5 text-body-sm font-semibold">
                      <Store className="size-3.5" />
                      {t("storefront.seller.reviews.reply")}
                    </p>
                    <p className="whitespace-pre-line text-body-sm">{review.seller_reply.body}</p>
                  </div>
                ) : null}
              </li>
            ))}
          </ul>
        )}
        {last > 1 ? (
          <nav className="mt-4 flex items-center justify-center gap-4 text-body" aria-label={t("common.pagination")}>
            {current > 1 ? (
              <Link href={pageHref(current - 1)} className="rounded-md border border-line bg-surface px-3 py-1.5">
                {t("storefront.seller.reviews.prev")}
              </Link>
            ) : null}
            <span className="text-secondary">{t("storefront.seller.reviews.page", { page: current, last })}</span>
            {current < last ? (
              <Link href={pageHref(current + 1)} className="rounded-md border border-line bg-surface px-3 py-1.5">
                {t("storefront.seller.reviews.next")}
              </Link>
            ) : null}
          </nav>
        ) : null}
      </section>
    </div>
  );
}

/** "About the store" (shown above the tabs when the store wrote a description). */
export function SellerAbout({ market, seller }: { market: Market; seller: SellerProfile }) {
  if (!seller.description) return null;
  const t = getT(market.locale);
  return (
    <section className="rounded-lg border border-line bg-surface p-5">
      <h2 className="mb-1 text-body font-semibold">{t("storefront.seller.about")}</h2>
      <p className="whitespace-pre-line text-body text-secondary">{seller.description}</p>
    </section>
  );
}
