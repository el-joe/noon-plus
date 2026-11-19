import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/missing">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("common.notFoundTitle"), robots: { index: false, follow: false } };
}

/**
 * QA-012 / RT-004: proxy.ts rewrites URLs whose item does not exist
 * (product, ad, post, unknown sub-path, …) here (lib/missing-routes.ts).
 * The page calls `notFound()` before anything renders, so the prerendered
 * route itself is a 404: `next start` keeps that status on the rewrite
 * (a status set on the rewrite alone is dropped for prerendered routes).
 * The market's not-found UI (Figma 71:10890) renders with its layout.
 */
export default function MissingPage(): never {
  notFound();
}
