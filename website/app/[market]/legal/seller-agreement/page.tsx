import type { Metadata } from "next";
import { ChevronRight, FileText } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { Alert, Container, Skeleton } from "@/components/ui/misc";
import { PortalNav } from "@/features/portal/portal-nav";
import { orNull } from "@/lib/api/client";
import { getCurrentAgreement, isPublicAgreement, type PublicAgreement } from "@/lib/api/portal";
import { formatDay } from "@/lib/format";
import { getT } from "@/lib/i18n";
import { COUNTRIES, LOCALES, marketSlug, type Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/legal/seller-agreement">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("portalPlus.legal.kinds.seller_platform"), description: t("portalPlus.legal.subtitle") };
}

const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);

/**
 * `/legal/seller-agreement` (Figma Legal Seller Agreement 100:5153,
 * PORTAL_PAGES §13, QC-042): the current published agreement of the market's
 * country from `GET contracts/current/{kind}`. `?agreement=` shows another
 * partner agreement (travel agency, broker, marketer, shipping company).
 */
export default async function SellerAgreementPage({ params, searchParams }: PageProps<"/[market]/legal/seller-agreement">) {
  const market = await resolveMarket(params);
  return (
    <>
      <PortalNav market={market} />
      <Container className="py-6">
        <Suspense fallback={<Skeleton className="h-[560px] w-full" />}>
          {searchParams.then((query) => {
            const requested = one(query.agreement);
            return <AgreementView market={market} kind={isPublicAgreement(requested) ? requested : "seller_platform"} />;
          })}
        </Suspense>
      </Container>
    </>
  );
}

async function AgreementView({ market, kind }: { market: Market; kind: PublicAgreement }) {
  const t = getT(market.locale);
  const agreement = await orNull(getCurrentAgreement(market.slug, kind).then((r) => r.data)).catch(() => undefined);
  const query = kind === "seller_platform" ? "" : `?agreement=${kind}`;
  const path = (slug: string) => `/${slug}/legal/seller-agreement${query}`;
  const title = t(`portalPlus.legal.kinds.${kind}`);

  return (
    <div className="flex flex-col gap-5">
      <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label={t("common.breadcrumb")}>
        <Link href={`/${market.slug}`} className="text-link">
          {t("common.home")}
        </Link>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <span>{t("portalPlus.legal.legal")}</span>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <span className="text-ink">{title}</span>
      </nav>
      <div>
        <h1 className="text-h1 font-bold">{title}</h1>
        <p className="mt-1 text-body text-secondary">{t("portalPlus.legal.subtitle")}</p>
      </div>

      <section className="rounded-lg border border-line bg-surface p-4">
        <p className="mb-2 text-caption font-medium uppercase text-muted">{t("portalPlus.legal.country")}</p>
        <div className="flex flex-wrap gap-2">
          {COUNTRIES.map((country) => {
            const active = country.code === market.country.code;
            return (
              <Link
                key={country.code}
                href={path(marketSlug(country.code, market.locale))}
                aria-current={active ? "page" : undefined}
                className={active ? "rounded-full bg-ink px-3 py-1.5 text-body-sm font-medium text-white" : "rounded-full border border-line px-3 py-1.5 text-body-sm hover:bg-subtle"}
              >
                {country.name[market.locale]}
              </Link>
            );
          })}
        </div>
      </section>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="flex min-w-0 flex-col gap-3">
          <div className="flex gap-4 border-b border-line" role="tablist" aria-label={t("portalPlus.legal.language")}>
            {LOCALES.map((locale) => (
              <Link
                key={locale}
                href={path(marketSlug(market.country.code, locale))}
                role="tab"
                aria-selected={locale === market.locale}
                className={locale === market.locale ? "-mb-px border-b-2 border-brand px-1 pb-2 text-body font-semibold" : "px-1 pb-2 text-body text-secondary hover:text-ink"}
              >
                {locale === "en" ? "English" : "العربية"}
              </Link>
            ))}
          </div>
          {agreement === undefined ? (
            <Alert tone="danger">{t("common.error")}</Alert>
          ) : agreement === null ? (
            <Alert tone="info">{t("portalPlus.legal.notPublished", { country: market.country.name[market.locale] })}</Alert>
          ) : agreement.text ? (
            <article className="rounded-lg bg-subtle p-4 md:p-8">
              <div className="mx-auto max-w-3xl whitespace-pre-line rounded-md bg-surface p-6 text-body shadow-xs md:p-10">{agreement.text}</div>
            </article>
          ) : (
            <Alert tone="info">{t("portalPlus.legal.fileOnly")}</Alert>
          )}
        </div>

        <aside className="flex flex-col gap-3 lg:sticky lg:top-32">
          <section className="rounded-lg border border-line bg-surface p-4">
            <h2 className="flex items-center gap-2 text-h4 font-semibold">
              <FileText className="size-5 text-brand-text" />
              {market.country.name[market.locale]}
            </h2>
            {agreement ? (
              <>
                <span className="mt-2 inline-flex rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-success-text">{t("portalPlus.legal.current")}</span>
                <dl className="mt-3 flex flex-col gap-2 text-body-sm">
                  <div className="flex justify-between gap-3">
                    <dt className="text-secondary">{t("portalPlus.legal.version")}</dt>
                    <dd className="font-medium">{String(agreement.version)}</dd>
                  </div>
                  {agreement.published_at ? (
                    <div className="flex justify-between gap-3">
                      <dt className="text-secondary">{t("portalPlus.legal.effective")}</dt>
                      <dd className="font-medium">{formatDay(agreement.published_at, market.locale)}</dd>
                    </div>
                  ) : null}
                </dl>
              </>
            ) : (
              <p className="mt-2 text-body-sm text-secondary">{t("portalPlus.legal.noVersion")}</p>
            )}
          </section>
          <section className="rounded-lg border border-line bg-surface p-4">
            <h2 className="mb-2 text-body font-semibold">{t("portalPlus.legal.otherAgreements")}</h2>
            <ul className="flex flex-col gap-1.5 text-body-sm">
              {(["seller_platform", "travel_agency", "broker", "marketer_onboarding", "shipping_company"] as const)
                .filter((k) => k !== kind)
                .map((k) => (
                  <li key={k}>
                    <Link href={`/${market.slug}/legal/seller-agreement${k === "seller_platform" ? "" : `?agreement=${k}`}`} className="text-link">
                      {t(`portalPlus.legal.kinds.${k}`)}
                    </Link>
                  </li>
                ))}
            </ul>
          </section>
        </aside>
      </div>
    </div>
  );
}
