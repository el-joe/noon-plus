import clsx from "clsx";
import { Newspaper } from "lucide-react";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/button";
import { Badge, EmptyState } from "@/components/ui/misc";
import { orNull } from "@/lib/api/client";
import { blogCategoryTree, getBlogCategories, getBlogPost, getBlogPosts, type BlogCategory, type BlogPostSummary } from "@/lib/api/content";
import { formatDay } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { sanitizeHtml } from "@/lib/sanitize";
import { Crumbs, Pager } from "./pagination";

/** Cover image or a neutral placeholder (no stock imagery). */
function Cover({ post, className }: { post: BlogPostSummary; className?: string }) {
  return (
    <div className={clsx("overflow-hidden bg-brand-subtle", className)}>
      {post.cover_url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={post.cover_url} alt="" loading="lazy" className="size-full object-cover" />
      ) : (
        <div className="flex size-full items-center justify-center text-brand-text">
          <Newspaper className="size-10" strokeWidth={1.5} />
        </div>
      )}
    </div>
  );
}

export function BlogCard({ market, post }: { market: Market; post: BlogPostSummary }) {
  return (
    <Link href={`/${market.slug}/blog/${encodeURIComponent(post.slug)}`} className="group flex flex-col overflow-hidden rounded-lg border border-line bg-surface hover:shadow-sm">
      <Cover post={post} className="aspect-[16/10] w-full" />
      <div className="flex flex-1 flex-col gap-1.5 p-3">
        {post.category?.name ? <Badge className="self-start">{post.category.name}</Badge> : null}
        <h3 className="line-clamp-2 text-body font-semibold group-hover:underline">{post.title}</h3>
        {post.excerpt ? <p className="line-clamp-2 text-body-sm text-secondary">{post.excerpt}</p> : null}
        {post.published_at ? <p className="mt-auto text-caption text-muted">{formatDay(post.published_at, market.locale)}</p> : null}
      </div>
    </Link>
  );
}

/** Blog categories as the nested tree (`meta.tree`, with post counts); the flat list nests by `parent_id` when the tree is missing. */
async function loadBlogCategories(market: string): Promise<BlogCategory[]> {
  try {
    const { data, meta } = await getBlogCategories(market);
    const tree = blogCategoryTree.safeParse(meta.tree);
    if (tree.success && tree.data.length > 0) return tree.data;
    const byParent = (parent: number | null): BlogCategory[] => data.filter((c) => c.parent_id === parent).map((c) => ({ ...c, children: byParent(c.id) }));
    return byParent(null);
  } catch {
    return [];
  }
}

function findBlogCategory(tree: BlogCategory[], slug: string | undefined, trail: BlogCategory[] = []): { node: BlogCategory; trail: BlogCategory[] } | null {
  if (!slug) return null;
  for (const node of tree) {
    if (node.slug === slug) return { node, trail: [...trail, node] };
    const hit = findBlogCategory(node.children ?? [], slug, [...trail, node]);
    if (hit) return hit;
  }
  return null;
}

/** Blog list (Figma Blog List 100:4258): featured hero, category chips, 12-per-page grid. */
export async function BlogList({ market, query }: { market: Market; query: Record<string, string | undefined> }) {
  const t = getT(market.locale);
  const page = Math.max(1, Number(query.page) || 1);
  const category = query.category;
  const [posts, categories] = await Promise.all([
    getBlogPosts(market.slug, { category, q: query.q, tag: query.tag, page }),
    loadBlogCategories(market.slug),
  ]);
  const lastPage = Number(posts.meta.last_page) || 1;
  const featured = page === 1 && !category && !query.q && !query.tag ? (posts.data.find((p) => p.is_featured) ?? null) : null;
  const rest = featured ? posts.data.filter((p) => p !== featured) : posts.data;
  const tags = [...new Set(posts.data.flatMap((p) => p.tags))].slice(0, 10);
  const base = category ? `/${market.slug}/blog/category/${encodeURIComponent(category)}` : `/${market.slug}/blog`;
  const found = findBlogCategory(categories, category);
  const currentCategory = found?.node;
  // Root chips; inside a category with children, its sub-categories too.
  const subCategories = found ? (found.node.children?.length ? found.node.children : (found.trail.at(-2)?.children ?? [])) : [];

  return (
    <div className="flex flex-col gap-6">
      <Crumbs
        market={market}
        items={[
          { label: t("content.blog.title"), href: `/${market.slug}/blog` },
          ...(found?.trail ?? []).map((c, i, all) => (i === all.length - 1 ? { label: c.name ?? c.slug } : { label: c.name ?? c.slug, href: `/${market.slug}/blog/category/${encodeURIComponent(c.slug)}` })),
        ]}
      />
      <header>
        <p className="text-caption font-semibold uppercase text-brand-text">{t("content.blog.kicker")}</p>
        <h1 className="text-h1 font-bold">{currentCategory?.name ?? t("content.blog.heading")}</h1>
      </header>

      {featured ? (
        <Link href={`/${market.slug}/blog/${encodeURIComponent(featured.slug)}`} className="grid overflow-hidden rounded-lg border border-line bg-surface md:grid-cols-2">
          <Cover post={featured} className="aspect-[16/10] w-full md:aspect-auto md:min-h-72" />
          <div className="flex flex-col justify-center gap-3 p-6">
            <div className="flex flex-wrap gap-2">
              <Badge tone="brand">{t("content.blog.featured")}</Badge>
              {featured.category?.name ? <Badge>{featured.category.name}</Badge> : null}
            </div>
            <h2 className="text-h2 font-bold">{featured.title}</h2>
            {featured.excerpt ? <p className="text-body text-secondary">{featured.excerpt}</p> : null}
            {featured.published_at ? <p className="text-caption text-muted">{formatDay(featured.published_at, market.locale)}</p> : null}
            <span className="inline-flex h-10 items-center self-start rounded-md bg-brand px-4 text-body font-semibold">{t("content.blog.readPost")}</span>
          </div>
        </Link>
      ) : null}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="scrollbar-none flex gap-2 overflow-x-auto">
          <Chip href={`/${market.slug}/blog`} active={!category}>
            {t("content.blog.all")}
          </Chip>
          {categories.map((c) => (
            <Chip key={c.slug} href={`/${market.slug}/blog/category/${encodeURIComponent(c.slug)}`} active={c.slug === category || found?.trail[0]?.slug === c.slug}>
              {c.name ?? c.slug}
              <span className="ms-1 opacity-70">{c.post_count}</span>
            </Chip>
          ))}
        </div>
        <form action={base} className="w-full sm:w-64">
          <input type="search" name="q" defaultValue={query.q ?? ""} placeholder={t("content.blog.searchPlaceholder")} className="h-10 w-full rounded-full border border-line-strong bg-surface px-4 text-body-sm" />
        </form>
      </div>
      {subCategories.length > 0 ? (
        <div className="scrollbar-none -mt-3 flex gap-2 overflow-x-auto">
          {subCategories.map((c) => (
            <Chip key={c.slug} href={`/${market.slug}/blog/category/${encodeURIComponent(c.slug)}`} active={c.slug === category}>
              {c.name ?? c.slug}
              <span className="ms-1 opacity-70">{c.post_count}</span>
            </Chip>
          ))}
        </div>
      ) : null}
      {tags.length > 0 ? (
        <div className="flex flex-wrap items-center gap-2 text-body-sm text-secondary">
          <span>{t("content.blog.tags")}:</span>
          {tags.map((tag) => (
            <Link key={tag} href={`/${market.slug}/blog?tag=${encodeURIComponent(tag)}`} className={clsx("rounded-full px-2 py-0.5", tag === query.tag ? "bg-ink text-white" : "bg-subtle hover:text-ink")}>
              #{tag}
            </Link>
          ))}
        </div>
      ) : null}

      {rest.length === 0 && !featured ? (
        <EmptyState icon={<Newspaper className="size-10" />} title={t("content.blog.empty")} body={t("content.blog.emptyBody")} />
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {rest.map((post) => (
            <BlogCard key={post.slug} market={market} post={post} />
          ))}
        </div>
      )}
      <Pager market={market} basePath={base} query={{ ...query, category: undefined }} page={page} lastPage={lastPage} />
    </div>
  );
}

function Chip({ href, active, children }: { href: string; active: boolean; children: React.ReactNode }) {
  return (
    <Link href={href} className={clsx("shrink-0 rounded-full border px-3 py-1.5 text-body-sm font-medium", active ? "border-ink bg-ink text-white" : "border-line bg-surface hover:bg-subtle")}>
      {children}
    </Link>
  );
}

/** Blog post (Figma Blog Post 100:4552) with related posts. */
export async function BlogArticle({ market, slug }: { market: Market; slug: string }) {
  const t = getT(market.locale);
  const post = await orNull(getBlogPost(market.slug, slug).then((r) => r.data));
  if (!post) notFound();
  return (
    <div className="flex flex-col gap-8">
      <Crumbs
        market={market}
        items={[
          { label: t("content.blog.title"), href: `/${market.slug}/blog` },
          ...(post.category ? [{ label: post.category.name ?? post.category.slug, href: `/${market.slug}/blog/category/${encodeURIComponent(post.category.slug)}` }] : []),
          { label: post.title ?? "" },
        ]}
      />
      <article className="mx-auto flex w-full max-w-3xl flex-col gap-4">
        {post.category?.name ? <Badge className="self-start">{post.category.name}</Badge> : null}
        <h1 className="text-h1 font-bold">{post.title}</h1>
        <p className="text-body-sm text-secondary">
          {post.published_at ? formatDay(post.published_at, market.locale) : null}
          {post.views ? ` · ${t("content.blog.views", { count: post.views })}` : null}
        </p>
        {post.cover_url ? <Cover post={post} className="aspect-[16/9] w-full rounded-lg" /> : null}
        {post.excerpt ? <p className="text-body-lg text-secondary">{post.excerpt}</p> : null}
        {post.body ? <div className="prose-cms text-body" dangerouslySetInnerHTML={{ __html: sanitizeHtml(post.body) }} /> : null}
        {post.tags.length > 0 ? (
          <div className="flex flex-wrap gap-2 border-t border-line pt-4">
            {post.tags.map((tag) => (
              <Link key={tag} href={`/${market.slug}/blog?tag=${encodeURIComponent(tag)}`} className="rounded-full bg-subtle px-2.5 py-0.5 text-body-sm text-secondary hover:text-ink">
                #{tag}
              </Link>
            ))}
          </div>
        ) : null}
        <ButtonLink href={`/${market.slug}/blog`} variant="outline" className="self-start">
          ‹ {t("content.blog.back")}
        </ButtonLink>
      </article>
      {post.related.length > 0 ? (
        <section>
          <h2 className="mb-3 text-h3 font-semibold">{t("content.blog.related")}</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {post.related.map((p) => (
              <BlogCard key={p.slug} market={market} post={p} />
            ))}
          </div>
        </section>
      ) : null}
    </div>
  );
}
