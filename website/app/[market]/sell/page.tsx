import type { Metadata } from "next";
import { BarChart3, Megaphone, Plane, ShoppingBag, Store, Truck, Wallet } from "lucide-react";
import Link from "next/link";
import { ButtonLink } from "@/components/ui/button";
import { Container } from "@/components/ui/misc";
import { FunnelView } from "@/features/portal/funnel";
import { PortalNav } from "@/features/portal/portal-nav";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/sell">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("portal.sell.title"), description: t("portal.sell.subtitle") };
}

/** `/sell` — sell-with-Nawy landing (Figma Sell landing 72:215, short form) into the join chooser. */
export default async function SellPage({ params }: PageProps<"/[market]/sell">) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  const why = [
    { key: "reach", icon: BarChart3 },
    { key: "payouts", icon: Wallet },
    { key: "fulfilment", icon: Truck },
  ] as const;
  const ways = [
    { key: "vendor", path: "/join/vendor", icon: ShoppingBag },
    { key: "open_souq", path: "/join/classified", icon: Store },
    { key: "travel_agency", path: "/sell/travel-agency", icon: Plane },
    { key: "marketer", path: "/marketers", icon: Megaphone },
    { key: "shipping", path: "/join/carrier", icon: Truck },
  ] as const;
  const how = ["register", "documents", "review", "sell"] as const;
  return (
    <>
      <PortalNav market={market} active="sell" />
      <FunnelView page="sell" />
      <section className="bg-ink text-white">
        <Container className="grid items-center gap-8 py-12 md:grid-cols-[1.2fr_1fr]">
          <div>
            <h1 className="text-h1 font-semibold">{t("portal.sell.title")}</h1>
            <p className="mt-3 text-body-lg opacity-80">{t("portal.sell.subtitle")}</p>
            <div className="mt-6 flex flex-wrap gap-3">
              <ButtonLink href={`/${market.slug}/join`} size="lg">
                {t("portal.sell.cta")}
              </ButtonLink>
              <ButtonLink href={`/${market.slug}/join/status`} size="lg" variant="outline" className="border-white/40 bg-transparent text-white hover:bg-white/10">
                {t("portal.nav.status")}
              </ButtonLink>
            </div>
          </div>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/img/lifestyle-flatlay.jpg" alt="" className="hidden aspect-[4/3] w-full rounded-lg object-cover md:block" />
        </Container>
      </section>
      <Container className="flex flex-col gap-10 py-10">
        <section className="grid gap-4 md:grid-cols-3">
          {why.map(({ key, icon: Icon }) => (
            <div key={key} className="rounded-lg border border-line bg-surface p-5">
              <Icon className="mb-3 size-6 text-brand-text" />
              <h2 className="text-h4 font-semibold">{t(`portal.sell.why.${key}.title`)}</h2>
              <p className="mt-1 text-body-sm text-secondary">{t(`portal.sell.why.${key}.body`)}</p>
            </div>
          ))}
        </section>
        <section>
          <h2 className="mb-4 text-h2 font-semibold">{t("portal.sell.waysTitle")}</h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {ways.map(({ key, path, icon: Icon }) => (
              <Link key={key} href={`/${market.slug}${path}`} className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-4 hover:shadow-sm">
                <Icon className="size-5 text-brand-text" />
                <span className="text-body font-semibold">{t(`portal.sections.${key}.title`)}</span>
                <span className="text-body-sm text-secondary">{t(`portal.sections.${key}.body`)}</span>
              </Link>
            ))}
          </div>
        </section>
        <section>
          <h2 className="mb-4 text-h2 font-semibold">{t("portal.sell.howTitle")}</h2>
          <ol className="grid gap-4 md:grid-cols-4">
            {how.map((step, i) => (
              <li key={step} className="rounded-lg border border-line bg-surface p-5">
                <span className="mb-2 flex size-8 items-center justify-center rounded-full bg-brand text-body font-semibold">{i + 1}</span>
                <p className="text-body font-semibold">{t(`portal.sell.how.${step}.title`)}</p>
                <p className="mt-1 text-body-sm text-secondary">{t(`portal.sell.how.${step}.body`)}</p>
              </li>
            ))}
          </ol>
        </section>
        <section className="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-brand-subtle p-6">
          <p className="text-h3 font-semibold">{t("portal.sell.ctaTitle")}</p>
          <ButtonLink href={`/${market.slug}/join`} size="lg">
            {t("portal.sell.cta")}
          </ButtonLink>
        </section>
      </Container>
    </>
  );
}
