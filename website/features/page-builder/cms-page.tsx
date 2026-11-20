import { orNull } from "@/lib/api/client";
import { getCategories, getPage } from "@/lib/api/endpoints";
import type { Category, CmsPage } from "@/lib/api/schemas";
import type { Market } from "@/lib/markets";

/** The admin preview token of a request (`?preview=`), if any. */
export function previewToken(searchParams: Record<string, string | string[] | undefined> | undefined): string | null {
  const value = searchParams?.preview;
  return typeof value === "string" && /^[A-Za-z0-9_.-]{10,400}$/.test(value) ? value : null;
}

/** Load a published page-builder page (or, with an admin `preview` token, its draft) and the category tree it may reference. */
export async function loadCmsPage(market: Market, slug: string, preview?: string | null): Promise<{ page: CmsPage | null; categories: Category[] }> {
  const [page, categories] = await Promise.all([
    orNull(getPage(market.slug, slug, preview).then((r) => r.data)).catch(() => null),
    getCategories(market.slug)
      .then((r) => r.data)
      .catch(() => [] as Category[]),
  ]);
  return { page, categories };
}
