import { orNull } from "@/lib/api/client";
import { getCategories, getPage } from "@/lib/api/endpoints";
import type { Category, CmsPage } from "@/lib/api/schemas";
import type { Market } from "@/lib/markets";

/** Admin preview of a request: the signed token (`?preview=`) and, while the editor is typing, its unsaved draft key (`&draft=`). */
export type PreviewParams = { token: string; draft: string | null };

export function previewToken(searchParams: Record<string, string | string[] | undefined> | undefined): PreviewParams | null {
  const value = searchParams?.preview;
  if (typeof value !== "string" || !/^[A-Za-z0-9_.-]{10,400}$/.test(value)) return null;
  const draft = searchParams?.draft;
  return { token: value, draft: typeof draft === "string" && /^[a-f0-9-]{16,64}$/.test(draft) ? draft : null };
}

/** Load a published page-builder page (or, with an admin `preview` token, its draft) and the category tree it may reference. */
export async function loadCmsPage(market: Market, slug: string, preview?: PreviewParams | null): Promise<{ page: CmsPage | null; categories: Category[] }> {
  const [page, categories] = await Promise.all([
    orNull(getPage(market.slug, slug, preview).then((r) => r.data)).catch(() => null),
    getCategories(market.slug)
      .then((r) => r.data)
      .catch(() => [] as Category[]),
  ]);
  return { page, categories };
}
