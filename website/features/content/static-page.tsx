import { notFound } from "next/navigation";
import { orNull } from "@/lib/api/client";
import { getStaticPage } from "@/lib/api/content";
import { formatDay } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { sanitizeHtml } from "@/lib/sanitize";
import { Crumbs } from "./pagination";

/** Static / legal page (Figma Static About 101:4452, Contact 101:4695, Policy template 101:4971, Legal page 71:10697). */
export async function StaticPageView({ market, slug }: { market: Market; slug: string }) {
  const t = getT(market.locale);
  const page = await orNull(getStaticPage(market.slug, slug).then((r) => r.data));
  if (!page) notFound();
  return (
    <div className="flex flex-col gap-6">
      <Crumbs market={market} items={[{ label: page.title ?? slug }]} />
      <article className="mx-auto flex w-full max-w-3xl flex-col gap-4 rounded-lg border border-line bg-surface p-5 md:p-10">
        <header className="flex flex-col gap-1 border-b border-line pb-4">
          <h1 className="text-h1 font-bold">{page.title}</h1>
          <p className="text-body-sm text-secondary">
            {page.updated_at ? t("content.pages.updated", { date: formatDay(page.updated_at, market.locale) }) : null}
            {page.country_specific ? ` · ${t("content.pages.countrySpecific", { country: market.country.name[market.locale] })}` : null}
          </p>
        </header>
        {page.body ? <div className="prose-cms text-body" dangerouslySetInnerHTML={{ __html: sanitizeHtml(page.body) }} /> : null}
      </article>
    </div>
  );
}
