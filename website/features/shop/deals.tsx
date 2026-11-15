import { Zap } from "lucide-react";
import Link from "next/link";
import type { ProductCardData } from "@/components/product/product-card";
import { ProductGrid } from "@/components/product/product-grid";
import { ButtonLink } from "@/components/ui/button";
import { Badge, EmptyState } from "@/components/ui/misc";
import { Countdown } from "@/features/page-builder/countdown";
import type { FlashSale } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

export function dealCards(sale: FlashSale): ProductCardData[] {
  return sale.deals.map((d) => ({
    slug: d.product.slug,
    title: d.product.title,
    brand: d.product.brand ?? null,
    image: d.product.image?.url ?? null,
    imageAlt: d.product.image?.alt ?? null,
    rating: d.product.rating ?? null,
    price: d.price,
    wasPrice: d.was_price,
    listingId: d.listing_id,
    claimedPercent: d.claimed_percent,
    soldOut: d.sold_out,
  }));
}

/** Public path segment of an event: its slug, or the id for older payloads. */
export function flashSalePath(sale: Pick<FlashSale, "id" | "slug">): string {
  return encodeURIComponent(sale.slug || String(sale.id));
}

/** Countdown band of one event: "Ends in" while live, "Starts in" before. */
export function SaleTimer({ sale, market, tone = "dark" }: { sale: FlashSale; market: Market; tone?: "dark" | "danger" }) {
  const t = getT(market.locale);
  const live = sale.state === "live";
  return (
    <span className="flex items-center gap-2 text-body-sm font-medium">
      {live ? t("shop.deals.endsIn") : t("shop.deals.startsIn")}
      <Countdown endsAt={live ? sale.ends_at : sale.starts_at} tone={tone} />
    </span>
  );
}

/** `/deals` (Figma Deals hub 67:9288): live flash sales first, then upcoming ones. */
export function DealsHub({ market, sales }: { market: Market; sales: FlashSale[] }) {
  const t = getT(market.locale);
  const live = sales.filter((s) => s.state === "live");
  const upcoming = sales.filter((s) => s.state !== "live");

  return (
    <div className="flex flex-col gap-6">
      <section className="rounded-xl bg-ink p-6 text-white md:p-8">
        <h1 className="text-h1 font-black uppercase text-brand">{t("shop.deals.title")}</h1>
        <p className="mt-1 text-body opacity-90">{t("shop.deals.subtitle")}</p>
        {sales.length > 0 ? (
          <div className="mt-4 flex flex-wrap gap-2">
            {sales.map((sale) => (
              <a key={sale.id} href={`#sale-${sale.id}`} className="rounded-full bg-brand px-3 py-1 text-body-sm font-semibold text-ink">
                {sale.name}
              </a>
            ))}
          </div>
        ) : null}
      </section>

      {upcoming[0] ? (
        <div className="flex flex-wrap items-center justify-center gap-3 rounded-lg bg-brand px-4 py-3 text-ink">
          <span className="text-body font-semibold">{upcoming[0].name}</span>
          <SaleTimer sale={upcoming[0]} market={market} />
        </div>
      ) : null}

      {sales.length === 0 ? (
        <EmptyState icon={<Zap className="size-10" />} title={t("shop.deals.empty")} body={t("shop.deals.emptyHint")} action={<ButtonLink href={`/${market.slug}`}>{t("shop.deals.browse")}</ButtonLink>} />
      ) : null}

      {[...live, ...upcoming].map((sale) => (
        <section key={sale.id} id={`sale-${sale.id}`} className="scroll-mt-32 rounded-xl bg-surface p-4 md:p-5">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-3">
              <h2 className="text-h3 font-bold">{sale.name}</h2>
              <Badge tone={sale.state === "live" ? "danger" : "info"}>{sale.state === "live" ? t("shop.deals.liveNow") : t("shop.deals.upcoming")}</Badge>
              <SaleTimer sale={sale} market={market} tone={sale.state === "live" ? "danger" : "dark"} />
            </div>
            <Link href={`/${market.slug}/flash-sale/${flashSalePath(sale)}`} className="text-body-sm font-semibold text-link">
              {t("shop.deals.viewEvent")} ({t("shop.deals.dealsCount", { count: sale.deals.length })})
            </Link>
          </div>
          {sale.deals.length > 0 ? <ProductGrid products={dealCards(sale).slice(0, 10)} columns={5} /> : <p className="text-body text-secondary">{t("shop.flash.empty")}</p>}
        </section>
      ))}
    </div>
  );
}

/** `/flash-sale/[id]` (Figma Flash sale 67:9994). */
export function FlashSaleView({ market, sale }: { market: Market; sale: FlashSale }) {
  const t = getT(market.locale);
  const live = sale.state === "live";
  return (
    <div className="flex flex-col gap-5">
      <Link href={`/${market.slug}/deals`} className="text-body-sm text-link">
        ‹ {t("shop.flash.backToDeals")}
      </Link>
      <section className="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-danger p-6 text-white md:p-8">
        <div>
          <p className="flex items-center gap-2 text-body font-semibold uppercase">
            <Zap className="size-5" />
            {t("shop.flash.badge")} · {live ? t("shop.flash.live") : t("shop.flash.upcoming")}
          </p>
          <h1 className="mt-1 text-h1 font-black">{sale.name}</h1>
          {sale.max_per_customer ? <p className="mt-1 text-body-sm opacity-90">{t("shop.deals.maxPerCustomer", { count: sale.max_per_customer })}</p> : null}
        </div>
        <div className="rounded-lg bg-white/15 px-4 py-3">
          <SaleTimer sale={sale} market={market} />
        </div>
      </section>
      {!live ? <p className="text-body text-secondary">{t("shop.flash.notStarted")}</p> : null}
      {sale.deals.length > 0 ? <ProductGrid products={dealCards(sale)} columns={5} /> : <EmptyState icon={<Zap className="size-10" />} title={t("shop.flash.empty")} />}
    </div>
  );
}
