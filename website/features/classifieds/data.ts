import * as api from "@/lib/api/classifieds";
import type { ClassifiedCategory } from "@/lib/api/classifieds";

const PROPERTY_VERTICALS = new Set(["property", "real_estate", "realestate"]);

/** Classified category tree + the slugs in the property vertical (`vertical` is already inherited by the API). */
export async function loadClassifiedCategories(market: string): Promise<{ tree: ClassifiedCategory[]; propertySlugs: string[] }> {
  const tree = await api
    .getClassifiedCategories(market)
    .then((r) => r.data)
    .catch(() => [] as ClassifiedCategory[]);
  const propertySlugs: string[] = [];
  const walk = (nodes: ClassifiedCategory[]) => {
    for (const node of nodes) {
      if (PROPERTY_VERTICALS.has(node.vertical ?? "")) propertySlugs.push(node.slug);
      walk(node.children ?? []);
    }
  };
  walk(tree);
  return { tree, propertySlugs };
}

export function findCategory(tree: ClassifiedCategory[], slug: string | undefined, trail: ClassifiedCategory[] = []): { node: ClassifiedCategory; trail: ClassifiedCategory[] } | null {
  if (!slug) return null;
  for (const node of tree) {
    if (node.slug === slug) return { node, trail: [...trail, node] };
    const found = findCategory(node.children ?? [], slug, [...trail, node]);
    if (found) return found;
  }
  return null;
}
