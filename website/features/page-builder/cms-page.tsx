import { orNull } from "@/lib/api/client";
import { getCategories, getPage } from "@/lib/api/endpoints";
import type { Category, CmsPage } from "@/lib/api/schemas";
import type { Market } from "@/lib/markets";

/** Load a published page-builder page and the category tree it may reference. */
export async function loadCmsPage(market: Market, slug: string): Promise<{ page: CmsPage | null; categories: Category[] }> {
  const [page, categories] = await Promise.all([
    orNull(getPage(market.slug, slug).then((r) => r.data)).catch(() => null),
    getCategories(market.slug)
      .then((r) => r.data)
      .catch(() => [] as Category[]),
  ]);
  return { page, categories };
}
