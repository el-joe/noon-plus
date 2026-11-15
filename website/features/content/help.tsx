import { BookOpen, FileText, Mail, Package, Pin, Search } from "lucide-react";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/button";
import { Badge, EmptyState } from "@/components/ui/misc";
import { ApiError, orNull } from "@/lib/api/client";
import { getHelpArticle, getHelpCollections, searchHelp, type HelpArticleSummary, type HelpCollection, type HelpHub } from "@/lib/api/content";
import { formatDay } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { sanitizeHtml } from "@/lib/sanitize";
import { Crumbs } from "./pagination";
import { HelpfulVote } from "./helpful-vote";

/**
 * Help centre (Figma 71:10213 / 98:8666 …) and the Ads Knowledge Hub
 * (97:7671 …) share these views; `HubConfig` picks the API hub and the route.
 */
export type HubConfig = { hub: HelpHub; path: "/help" | "/adsupport"; titleKey: "content.help.title" | "content.adsupport.title" };

export const HELP_CENTER: HubConfig = { hub: "help_center", path: "/help", titleKey: "content.help.title" };
export const KNOWLEDGE_HUB: HubConfig = { hub: "knowledge_hub", path: "/adsupport", titleKey: "content.adsupport.title" };

const isAds = (config: HubConfig) => config.hub === "knowledge_hub";

function SearchHero({ market, config, q }: { market: Market; config: HubConfig; q?: string }) {
  const t = getT(market.locale);
  return (
    <section className="rounded-lg bg-brand-subtle px-4 py-10 md:py-14">
      <div className="mx-auto flex max-w-2xl flex-col gap-4 text-center">
        <h1 className="text-h1 font-bold">{isAds(config) ? t("content.adsupport.heroTitle") : t("content.help.heroTitle")}</h1>
        <form action={`/${market.slug}${config.path}/search`} className="flex gap-2 text-start" role="search">
          <label className="flex flex-1 flex-col gap-1">
            <span className="sr-only">{isAds(config) ? t("content.adsupport.searchLabel") : t("content.help.searchLabel")}</span>
            <input
              type="search"
              name="q"
              defaultValue={q ?? ""}
              minLength={2}
              maxLength={100}
              required
              placeholder={isAds(config) ? t("content.adsupport.searchPlaceholder") : t("content.help.searchPlaceholder")}
              className="h-11 w-full rounded-md border border-line-strong bg-surface px-3 text-body"
            />
          </label>
          <button type="submit" className="inline-flex h-11 items-center gap-2 rounded-md bg-brand px-5 text-body font-semibold">
            <Search className="size-4" />
            <span className="hidden sm:inline">{t("content.help.search")}</span>
          </button>
        </form>
      </div>
    </section>
  );
}

function ArticleLink({ market, config, article }: { market: Market; config: HubConfig; article: HelpArticleSummary }) {
  const t = getT(market.locale);
  return (
    <Link href={`/${market.slug}${config.path}/articles/${encodeURIComponent(article.slug)}`} className="flex items-center gap-2 rounded-md px-3 py-2.5 text-body hover:bg-subtle">
      <FileText className="size-4 shrink-0 text-secondary" />
      <span className="flex-1">{article.title}</span>
      {article.is_pinned ? (
        <Badge tone="warning">
          <Pin className="size-3" />
          {t("content.help.pinned")}
        </Badge>
      ) : null}
    </Link>
  );
}

function ContactCards({ market }: { market: Market }) {
  const t = getT(market.locale);
  return (
    <section className="grid gap-3 md:grid-cols-2">
      <div className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-4">
        <p className="text-body font-semibold">{t("content.help.contactTitle")}</p>
        <p className="text-body-sm text-secondary">{t("content.help.emailHint")}</p>
        <a href="mailto:care@nawy.com" className="inline-flex h-8 items-center gap-2 self-start rounded-md border border-line-strong px-3 text-label font-semibold">
          <Mail className="size-4" />
          {t("content.help.sendEmail")}
        </a>
      </div>
      <div className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-4">
        <p className="text-body font-semibold">{t("content.help.recentOrder")}</p>
        <p className="text-body-sm text-secondary">{t("content.help.recentOrderBody")}</p>
        <Link href={`/${market.slug}/account/orders`} className="inline-flex h-8 items-center gap-2 self-start rounded-md border border-line-strong px-3 text-label font-semibold">
          <Package className="size-4" />
          {t("content.help.myOrders")}
        </Link>
      </div>
    </section>
  );
}

async function loadCollections(market: Market, config: HubConfig): Promise<HelpCollection[]> {
  return getHelpCollections(market.slug, config.hub)
    .then((r) => r.data)
    .catch((error) => {
      if (error instanceof ApiError && error.status === 404) return [];
      throw error;
    });
}

/** Hub home: search hero, topic cards, popular articles. */
export async function HelpHome({ market, config }: { market: Market; config: HubConfig }) {
  const t = getT(market.locale);
  const collections = await loadCollections(market, config);
  const popular = collections
    .flatMap((c) => c.articles)
    .sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned) || b.views - a.views)
    .slice(0, 8);

  return (
    <div className="flex flex-col gap-6">
      <SearchHero market={market} config={config} />
      {!isAds(config) ? <ContactCards market={market} /> : null}
      <section>
        <h2 className="mb-3 text-h3 font-semibold">{t("content.help.browseTopics")}</h2>
        {collections.length === 0 ? (
          <EmptyState icon={<BookOpen className="size-10" />} title={t("content.help.empty")} body={t("content.help.emptyBody")} />
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {collections.map((c) => (
              <Link key={c.slug} href={`/${market.slug}${config.path}/collections/${encodeURIComponent(c.slug)}`} className="flex flex-col gap-1 rounded-lg border border-line bg-surface p-4 hover:shadow-sm">
                <span className="text-body font-semibold">{c.title}</span>
                {c.description ? <span className="line-clamp-2 text-body-sm text-secondary">{c.description}</span> : null}
                <span className="mt-1 text-caption text-muted">{t("content.help.articles", { count: c.articles_count })}</span>
              </Link>
            ))}
          </div>
        )}
      </section>
      {popular.length > 0 ? (
        <section className="rounded-lg border border-line bg-surface p-2">
          <h2 className="px-3 pb-1 pt-2 text-h4 font-semibold">{t("content.help.popular")}</h2>
          {popular.map((a) => (
            <ArticleLink key={a.slug} market={market} config={config} article={a} />
          ))}
        </section>
      ) : null}
      {isAds(config) ? (
        <ButtonLink href={`/${market.slug}/advertise`} className="self-start">
          {t("content.adsupport.advertise")}
        </ButtonLink>
      ) : null}
    </div>
  );
}

/** Search results (Figma Help Center Search 98:8896). */
export async function HelpSearch({ market, config, q }: { market: Market; config: HubConfig; q: string }) {
  const t = getT(market.locale);
  const term = q.trim();
  const results = term.length >= 2 ? await searchHelp(market.slug, config.hub, term).then((r) => r.data) : null;
  return (
    <div className="flex flex-col gap-6">
      <Crumbs market={market} items={[{ label: t(config.titleKey), href: `/${market.slug}${config.path}` }, { label: t("content.help.search") }]} />
      <SearchHero market={market} config={config} q={term} />
      {results === null ? (
        <p className="text-body text-secondary">{t("content.help.searchPrompt")}</p>
      ) : results.length === 0 ? (
        <EmptyState icon={<Search className="size-10" />} title={t("content.help.noResults")} body={t("content.help.noResultsBody")} />
      ) : (
        <section className="rounded-lg border border-line bg-surface p-2">
          <h2 className="px-3 pb-1 pt-2 text-h4 font-semibold">{t("content.help.resultsFor", { q: term })}</h2>
          {results.map((a) => (
            <ArticleLink key={a.slug} market={market} config={config} article={a} />
          ))}
        </section>
      )}
    </div>
  );
}

/** One collection / category (Figma 98:9129, Ads KH Collection 97:7920). */
export async function HelpCollectionView({ market, config, slug }: { market: Market; config: HubConfig; slug: string }) {
  const t = getT(market.locale);
  const collections = await loadCollections(market, config);
  const collection = collections.find((c) => c.slug === slug);
  if (!collection) notFound();
  return (
    <div className="grid gap-6 lg:grid-cols-[260px_1fr]">
      <div className="lg:col-span-2">
        <Crumbs market={market} items={[{ label: t(config.titleKey), href: `/${market.slug}${config.path}` }, { label: collection.title ?? slug }]} />
      </div>
      <TopicNav market={market} config={config} collections={collections} current={slug} />
      <section className="flex flex-col gap-4">
        <header>
          <h1 className="text-h1 font-bold">{collection.title}</h1>
          {collection.description ? <p className="text-body text-secondary">{collection.description}</p> : null}
          <p className="mt-1 text-caption text-muted">{t("content.help.articles", { count: collection.articles_count })}</p>
        </header>
        <div className="rounded-lg border border-line bg-surface p-2">
          {collection.articles.length === 0 ? (
            <p className="p-3 text-body text-secondary">{t("content.help.empty")}</p>
          ) : (
            [...collection.articles].sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned)).map((a) => <ArticleLink key={a.slug} market={market} config={config} article={a} />)
          )}
        </div>
      </section>
    </div>
  );
}

function TopicNav({ market, config, collections, current }: { market: Market; config: HubConfig; collections: HelpCollection[]; current: string }) {
  const t = getT(market.locale);
  return (
    <aside className="flex flex-col gap-1 self-start rounded-lg border border-line bg-surface p-2">
      <p className="px-2 pb-1 pt-1 text-caption font-medium uppercase text-muted">{t("content.help.browseTopics")}</p>
      {collections.map((c) => (
        <Link
          key={c.slug}
          href={`/${market.slug}${config.path}/collections/${encodeURIComponent(c.slug)}`}
          aria-current={c.slug === current ? "page" : undefined}
          className={c.slug === current ? "rounded-md bg-brand-subtle px-3 py-2 text-body font-semibold" : "rounded-md px-3 py-2 text-body hover:bg-subtle"}
        >
          {c.title}
        </Link>
      ))}
    </aside>
  );
}

/** Article (Figma Help article 71:10487 / 98:9364, Ads KH Article 97:8135). */
export async function HelpArticleView({ market, config, slug }: { market: Market; config: HubConfig; slug: string }) {
  const t = getT(market.locale);
  const article = await orNull(getHelpArticle(market.slug, config.hub, slug).then((r) => r.data));
  if (!article) notFound();
  const collections = await loadCollections(market, config).catch(() => []);
  const collection = collections.find((c) => c.slug === article.collection);
  const crumb = collection ?? (article.collection_detail ? { slug: article.collection_detail.slug, title: article.collection_detail.title } : null);
  const siblings = collection?.articles.filter((a) => a.slug !== article.slug).slice(0, 8) ?? [];
  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
      <div className="lg:col-span-2">
        <Crumbs
          market={market}
          items={[
            { label: t(config.titleKey), href: `/${market.slug}${config.path}` },
            ...(crumb ? [{ label: crumb.title ?? crumb.slug, href: `/${market.slug}${config.path}/collections/${encodeURIComponent(crumb.slug)}` }] : []),
            { label: article.title ?? "" },
          ]}
        />
      </div>
      <article className="flex flex-col gap-4 rounded-lg border border-line bg-surface p-5 md:p-8">
        <h1 className="text-h1 font-bold">{article.title}</h1>
        {article.updated_at ? <p className="-mt-2 text-caption text-muted">{t("content.help.updated", { date: formatDay(article.updated_at, market.locale) })}</p> : null}
        {article.body ? <div className="prose-cms text-body" dangerouslySetInnerHTML={{ __html: sanitizeHtml(article.body) }} /> : null}
        <HelpfulVote hub={config.hub} slug={article.slug} initial={article.helpful} />
      </article>
      <aside className="flex flex-col gap-4 self-start">
        {siblings.length > 0 ? (
          <section className="rounded-lg border border-line bg-surface p-2">
            <h2 className="px-3 pb-1 pt-2 text-h4 font-semibold">{t("content.help.inThisTopic")}</h2>
            {siblings.map((a) => (
              <ArticleLink key={a.slug} market={market} config={config} article={a} />
            ))}
          </section>
        ) : null}
        {!isAds(config) ? (
          <div className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-4">
            <p className="text-body font-semibold">{t("content.help.contactTitle")}</p>
            <p className="text-body-sm text-secondary">{t("content.help.emailHint")}</p>
            <a href="mailto:care@nawy.com" className="text-body-sm font-semibold text-link">
              {t("content.help.sendEmail")}
            </a>
          </div>
        ) : null}
      </aside>
    </div>
  );
}
