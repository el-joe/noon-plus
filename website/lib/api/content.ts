import { z } from "zod";
import { get, request, type Query } from "./client";

/*
 * Content API (ContentApiController): blog, help centre / Ads Knowledge Hub
 * and static pages. All public reads in the market's language.
 */

// ContentApiController@postSummary
export const blogPostSummary = z.object({
  slug: z.string(),
  title: z.string().nullable(),
  excerpt: z.string().nullable(),
  cover_url: z.string().nullable(),
  category: z.object({ slug: z.string(), name: z.string().nullable() }).nullable(),
  tags: z.array(z.string()).default([]),
  is_featured: z.boolean().default(false),
  published_at: z.string().nullable(),
});
export type BlogPostSummary = z.infer<typeof blogPostSummary>;

export const blogPost = blogPostSummary.extend({
  body: z.string().nullable(),
  views: z.number().nullable().optional(),
  related: z.array(blogPostSummary).default([]),
});
export type BlogPost = z.infer<typeof blogPost>;

export type BlogCategory = { id: number; slug: string; name: string | null; parent_id: number | null; post_count: number; children?: BlogCategory[] };
export const blogCategory: z.ZodType<BlogCategory> = z.lazy(() =>
  z.object({
    id: z.number(),
    slug: z.string(),
    name: z.string().nullable(),
    parent_id: z.number().nullable(),
    post_count: z.number().default(0),
    children: z.array(blogCategory).optional(),
  }),
);
/** `meta.tree` of `blog/categories`: the nested category tree (round 2). */
export const blogCategoryTree = z.array(blogCategory);

export type BlogFilters = { category?: string; tag?: string; q?: string; featured?: boolean; page?: number };
export const getBlogPosts = (market: string, filters: BlogFilters = {}) => get(market, "blog", z.array(blogPostSummary), filters as Query);
export const getBlogCategories = (market: string) => get(market, "blog/categories", z.array(blogCategory));
export const getBlogPost = (market: string, slug: string) => get(market, `blog/${encodeURIComponent(slug)}`, blogPost);

// Help centre (`help_center`) and Ads Knowledge Hub (`knowledge_hub`)
export type HelpHub = "help_center" | "knowledge_hub";

export const helpArticleSummary = z.object({
  slug: z.string(),
  title: z.string().nullable(),
  is_pinned: z.boolean().default(false),
  views: z.number().default(0),
  helpful_count: z.number().optional(),
});
export type HelpArticleSummary = z.infer<typeof helpArticleSummary>;

export const helpCollection = z.object({
  slug: z.string(),
  title: z.string().nullable(),
  description: z.string().nullable(),
  audience: z.string().nullable(),
  articles_count: z.number().default(0),
  articles: z.array(helpArticleSummary).default([]),
});
export type HelpCollection = z.infer<typeof helpCollection>;

const helpfulCounts = z.object({ yes: z.number(), no: z.number() });
export const helpArticle = helpArticleSummary.extend({
  body: z.string().nullable(),
  collection: z.string(),
  collection_detail: z.object({ slug: z.string(), title: z.string().nullable(), audience: z.string().nullable().optional() }).nullable().optional(),
  helpful: helpfulCounts.optional(),
  updated_at: z.string().nullable().optional(),
});
export type HelpArticle = z.infer<typeof helpArticle>;

export const getHelpCollections = (market: string, hub: HelpHub, audience?: string) =>
  get(market, `help/${hub}/collections`, z.array(helpCollection), { audience });
export const searchHelp = (market: string, hub: HelpHub, q: string) => get(market, `help/${hub}/search`, z.array(helpArticleSummary), { q });
export const getHelpArticle = (market: string, hub: HelpHub, slug: string) => get(market, `help/${hub}/articles/${encodeURIComponent(slug)}`, helpArticle);

/** One vote per user / visitor token / IP; voting again switches it. */
export const voteHelpArticle = (market: string, hub: HelpHub, slug: string, helpful: boolean) =>
  request(market, `help/${hub}/articles/${encodeURIComponent(slug)}/votes`, z.object({ helpful: helpfulCounts, my_vote: z.boolean().nullable().optional() }), { method: "POST", body: { helpful } });

// ContentApiController@staticPage
export const staticPage = z.object({
  slug: z.string(),
  title: z.string().nullable(),
  body: z.string().nullable(),
  country_specific: z.boolean().default(false),
  /** QA-032: neutral placeholder text that legal has not replaced yet. */
  placeholder: z.boolean().optional().default(false),
  updated_at: z.string().nullable(),
  seo: z.object({ title: z.string().nullable(), description: z.string().nullable() }).nullable().optional(),
});
export type StaticPage = z.infer<typeof staticPage>;
export const getStaticPage = (market: string, slug: string) => get(market, `static-pages/${encodeURIComponent(slug)}`, staticPage, { audience: "storefront" });
