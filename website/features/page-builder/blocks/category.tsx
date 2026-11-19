import clsx from "clsx";
import { ChevronRight, Home } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { ProductListing } from "@/features/listing/product-listing";
import type { Category, Money } from "@/lib/api/schemas";
import type { Market } from "@/lib/markets";
import { getT } from "@/lib/i18n";
import { Countdown } from "../countdown";
import { item, items, num, resolveLink, safeUrl, str, type BlockContext, type Settings } from "../settings";
import { offerCaption } from "./category-promos";
import { BlockSection } from "./shared";

function children(ctx: BlockContext): { list: Category[]; base: string } {
  if (ctx.category) return { list: ctx.category.children, base: `/${ctx.market.slug}/${ctx.category.path.join("/")}` };
  return { list: ctx.categories, base: `/${ctx.market.slug}` };
}

/** A child category on the category page, with its offer stats (from `categories/{slug}`) when known. */
export type CategoryChild = Category & { min_price?: Money | null; max_discount?: number };

/**
 * 46 subcategory_tiles — image tiles of the category's children; "All" first.
 * `style: "circle"` (category page design): round images with the name and a
 * caption from real offers ("Up to 40%", "From AED 499"), no "All" tile.
 */
export function SubcategoryTiles({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const { list, base } = children(ctx);
  if (list.length === 0) return null;
  const t = getT(ctx.market.locale);
  const max = num(s, "max_rows", 2) * 8;
  if (str(s, "style") === "circle") {
    return (
      <BlockSection s={{ ...s, surface: s.surface ?? false }} ctx={ctx}>
        <div className="scrollbar-none -mx-1 flex gap-3 overflow-x-auto px-1 pb-1 lg:grid lg:grid-cols-9 lg:overflow-visible">
          {(list as CategoryChild[]).slice(0, num(s, "max_items", 9)).map((c) => {
            const caption = offerCaption(t, ctx.market, c.min_price ?? null, c.max_discount ?? null, true);
            return (
              <Link key={c.slug} href={`${base}/${c.slug}`} className="flex w-20 shrink-0 flex-col items-center gap-1 text-center lg:w-auto">
                <span className="flex aspect-square w-full items-center justify-center overflow-hidden rounded-full bg-surface ring-1 ring-line">
                  {safeUrl(c.image) ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={c.image!} alt="" loading="lazy" className="size-4/5 object-contain" />
                  ) : (
                    <span className="text-h3 font-bold text-brand-text">{c.name.slice(0, 1)}</span>
                  )}
                </span>
                <span className="line-clamp-1 text-body-sm font-medium">{c.name}</span>
                {caption ? <span className="text-caption font-semibold text-success-text">{caption}</span> : null}
              </Link>
            );
          })}
        </div>
      </BlockSection>
    );
  }
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className="grid grid-cols-4 gap-3 sm:grid-cols-6 lg:grid-cols-8">
        <Link href={base} className="flex flex-col items-center gap-1.5 text-center">
          <span className="flex aspect-square w-full items-center justify-center rounded-lg bg-ink text-body font-semibold text-white ring-2 ring-brand">{t("listing.all")}</span>
          <span className="text-body-sm font-medium">{ctx.category?.name ?? t("listing.all")}</span>
        </Link>
        {list.slice(0, max - 1).map((c) => (
          <Link key={c.slug} href={`${base}/${c.slug}`} className="flex flex-col items-center gap-1.5 text-center">
            <span className="aspect-square w-full overflow-hidden rounded-lg bg-surface">
              {safeUrl(c.image) ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={c.image!} alt="" loading="lazy" className="size-full object-cover" />
              ) : (
                <span className="flex size-full items-center justify-center bg-brand-subtle text-h3 font-bold text-brand-text">{c.name.slice(0, 1)}</span>
              )}
            </span>
            <span className="line-clamp-2 text-body-sm font-medium">{c.name}</span>
          </Link>
        ))}
      </div>
    </BlockSection>
  );
}

/** 47 subcategory_hub — grouped cards (child + its children). */
export function SubcategoryHub({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const { list, base } = children(ctx);
  if (list.length === 0) return null;
  const t = getT(ctx.market.locale);
  const perCard = num(s, "max_links", 6);
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {list.map((c) => (
          <div key={c.slug} className="rounded-lg border border-line bg-surface p-4">
            <Link href={`${base}/${c.slug}`} className="text-h4 font-semibold hover:underline">
              {c.name}
            </Link>
            <ul className="mt-2 flex flex-col gap-1">
              {(c.children ?? []).slice(0, perCard).map((g) => (
                <li key={g.slug}>
                  <Link href={`${base}/${c.slug}/${g.slug}`} className="text-body-sm text-secondary hover:text-ink">
                    {g.name}
                  </Link>
                </li>
              ))}
            </ul>
            <Link href={`${base}/${c.slug}`} className="mt-2 inline-block text-body-sm text-link">
              {t("common.viewAll")} ›
            </Link>
          </div>
        ))}
      </div>
    </BlockSection>
  );
}

/**
 * 48 subcategory_chips — chip bar above the listing. `style: "boxes"`
 * (category page design): a row of white boxes, the current one outlined.
 */
export function SubcategoryChips({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const { list, base } = children(ctx);
  if (list.length === 0) return null;
  const t = getT(ctx.market.locale);
  const active = ctx.category?.path.at(-1);
  if (str(s, "style") === "boxes") {
    return (
      <nav className="scrollbar-none -mx-1 flex gap-2 overflow-x-auto px-1 py-1" aria-label={t("listing.subcategories")}>
        {list.map((c) => (
          <Link key={c.slug} href={`${base}/${c.slug}`} className={clsx("min-w-24 shrink-0 rounded-md border bg-surface px-4 py-3 text-center text-body-sm font-medium hover:border-ink", active === c.slug ? "border-ink" : "border-line")}>
            {c.name}
          </Link>
        ))}
      </nav>
    );
  }
  return (
    <nav className={clsx("scrollbar-none flex gap-2 overflow-x-auto py-1", s.sticky !== false && "sticky top-[116px] z-10 bg-page")}>
      <Link href={base} className={clsx("shrink-0 rounded-full border px-3 py-1.5 text-body-sm font-medium", "border-ink bg-ink text-white")}>
        {t("listing.all")}
      </Link>
      {list.map((c) => (
        <Link key={c.slug} href={`${base}/${c.slug}`} className={clsx("shrink-0 rounded-full border px-3 py-1.5 text-body-sm", active === c.slug ? "border-ink bg-ink text-white" : "border-line bg-surface")}>
          {c.name}
        </Link>
      ))}
    </nav>
  );
}

/** Category page breadcrumb (design): Home › Electronics › Mobiles. */
export function CategoryBreadcrumb({ market, crumbs }: { market: Market; crumbs: { label: string; href: string }[] }) {
  const t = getT(market.locale);
  return (
    <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label={t("common.breadcrumb")}>
      <Link href={`/${market.slug}`} aria-label={t("common.home")} className="hover:text-ink">
        <Home className="size-3.5" />
      </Link>
      {crumbs.map((crumb, i) => (
        <span key={crumb.href} className="flex items-center gap-1.5">
          <ChevronRight className="size-3.5 rtl:rotate-180" />
          {i === crumbs.length - 1 ? (
            <span aria-current="page" className="text-ink">
              {crumb.label}
            </span>
          ) : (
            <Link href={crumb.href} className="hover:text-ink">
              {crumb.label}
            </Link>
          )}
        </span>
      ))}
    </nav>
  );
}

/** 49 category_header — dark band: breadcrumb, name, description, optional banner. */
export function CategoryHeader({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  const name = str(s, "title", ctx.market.locale) ?? ctx.category?.name;
  if (!name) return null;
  const description = str(s, "description", ctx.market.locale);
  const banner = safeUrl(str(s, "image") ?? str(s, "banner"));
  return (
    <section className="relative overflow-hidden rounded-lg bg-ink p-5 text-white">
      {banner ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={banner} alt="" className="absolute inset-0 size-full object-cover opacity-30" />
      ) : null}
      <div className="relative">
        <nav className="mb-2 flex items-center gap-1.5 text-body-sm opacity-80" aria-label={t("common.breadcrumb")}>
          <Link href={`/${ctx.market.slug}`} aria-label={t("common.home")}>
            <Home className="size-3.5" />
          </Link>
          <ChevronRight className="size-3.5 rtl:rotate-180" />
          <span>{name}</span>
        </nav>
        <h1 className="text-h1 font-bold">{name}</h1>
        {description ? <p className="mt-1 max-w-2xl text-body opacity-90">{description}</p> : null}
      </div>
    </section>
  );
}

/** 50 brand_header — brand / seller / marketer store header. */
export function BrandHeader({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  const brand = item(s, ctx.market.locale);
  const name = str(s, "name", ctx.market.locale) ?? brand.label;
  if (!name) return null;
  const cover = safeUrl(str(s, "cover"));
  const logo = safeUrl(str(s, "logo"));
  const tabs = items(s, "tabs", ctx.market.locale);
  return (
    <section className="overflow-hidden rounded-lg bg-surface">
      <div className="h-32 bg-brand-subtle md:h-48" style={cover ? { backgroundImage: `url("${cover}")`, backgroundSize: "cover", backgroundPosition: "center" } : undefined} />
      <div className="flex flex-wrap items-center gap-4 p-4">
        <span className="-mt-12 flex size-20 items-center justify-center overflow-hidden rounded-lg border-4 border-surface bg-surface shadow-sm">
          {logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logo} alt="" className="size-full object-contain" />
          ) : (
            <span className="text-h2 font-bold">{name.slice(0, 1)}</span>
          )}
        </span>
        <div className="flex-1">
          <h1 className="flex items-center gap-2 text-h2 font-bold">
            {name}
            {s.official ? <span className="rounded-xs bg-link px-1.5 text-caption font-semibold text-white">{t("blocks.official")}</span> : null}
          </h1>
          {brand.caption ? <p className="text-body text-secondary">{brand.caption}</p> : null}
        </div>
      </div>
      {tabs.length > 0 ? (
        <nav className="flex gap-6 border-t border-line px-4">
          {tabs.map((tab, i) => (
            <Link key={i} href={resolveLink(tab.link, ctx.market) ?? "#"} className={clsx("py-3 text-body", i === 0 ? "border-b-2 border-brand font-semibold" : "text-secondary")}>
              {tab.label}
            </Link>
          ))}
        </nav>
      ) : null}
    </section>
  );
}

/** 51 campaign_hero — title, subline, countdown, jump chips. */
export function CampaignHero({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const title = str(s, "title", ctx.market.locale);
  if (!title) return null;
  const endsAt = str(s, "ends_at");
  const chips = items(s, "chips", ctx.market.locale);
  const image = safeUrl(str(s, "image") ?? str(s, "image_d"));
  return (
    <section className="relative overflow-hidden rounded-lg bg-brand p-6">
      {image ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={image} alt="" className="absolute inset-0 size-full object-cover" />
      ) : null}
      <div className="relative flex flex-col gap-3">
        <h1 className="text-h1 font-extrabold">{title}</h1>
        {str(s, "subline", ctx.market.locale) ? <p className="text-body-lg">{str(s, "subline", ctx.market.locale)}</p> : null}
        {endsAt ? <Countdown endsAt={endsAt} /> : null}
        {chips.length > 0 ? (
          <div className="flex flex-wrap gap-2">
            {chips.map((chip, i) => (
              <a key={i} href={chip.link?.startsWith("#") ? chip.link : (resolveLink(chip.link, ctx.market) ?? "#")} className="rounded-full bg-surface px-3 py-1.5 text-body-sm font-medium">
                {chip.label}
              </a>
            ))}
          </div>
        ) : null}
      </div>
    </section>
  );
}

/** 32 product_listing — the listing zone on category / campaign / brand / search templates. */
export function ProductListingBlock({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const scope = str(s, "scope");
  const category = ctx.category?.path.at(-1) ?? (scope && scope.startsWith("category:") ? scope.slice(9) : str(s, "category"));
  const query = { ...(ctx.listing?.query ?? {}) };
  if (!query.sort && str(s, "default_sort")) query.sort = str(s, "default_sort") ?? undefined;
  return (
    <Suspense fallback={<Skeleton className="h-[600px] w-full" />}>
      <ProductListing market={ctx.market} query={query} basePath={ctx.listing?.basePath ?? `/${ctx.market.slug}/search`} category={category ?? null} />
    </Suspense>
  );
}
