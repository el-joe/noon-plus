import type { Metadata } from "next";
import { Armchair, FileCheck2, FileSignature, Megaphone, Star, Wallet } from "lucide-react";
import { FunnelView } from "@/features/portal/funnel";
import { CtaBand, Faq, FeatureGrid, LandingHero, LandingPage } from "@/features/portal/landing";
import { PortalNav } from "@/features/portal/portal-nav";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/sell/travel-agency">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("portalPlus.travel.metaTitle"), description: t("portalPlus.travel.subtitle") };
}

/**
 * `/sell/travel-agency` — travel agency landing (Figma Travel landing 93:1481,
 * PORTAL_PAGES §5 `/companies/travel-agency`, QC-042). D8: travellers send
 * booking requests; no payment is taken on Nawy.
 */
export default async function TravelAgencyLandingPage({ params }: PageProps<"/[market]/sell/travel-agency">) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  const base = `/${market.slug}`;
  const register = `${base}/join/travel_agency`;
  const why = [
    { icon: Armchair, key: "seats" },
    { icon: FileCheck2, key: "documents" },
    { icon: FileSignature, key: "contracts" },
    { icon: Wallet, key: "requests" },
    { icon: Megaphone, key: "promotions" },
    { icon: Star, key: "reviews" },
  ] as const;
  return (
    <>
      <PortalNav market={market} active="travel" />
      <FunnelView page="sell/travel-agency" />
      <LandingPage>
        <LandingHero
          eyebrow={t("portalPlus.travel.eyebrow")}
          title={t("portalPlus.travel.title")}
          body={t("portalPlus.travel.subtitle")}
          primary={{ label: t("portalPlus.travel.register"), href: register }}
          secondary={{ label: t("portalPlus.travel.agreement"), href: `${base}/legal/seller-agreement?agreement=travel_agency` }}
        />
        <FeatureGrid
          title={t("portalPlus.travel.whyTitle")}
          items={why.map(({ icon, key }) => ({ icon, title: t(`portalPlus.travel.why.${key}.title`), body: t(`portalPlus.travel.why.${key}.body`) }))}
        />
        <Faq title={t("portalPlus.common.questions")} items={([1, 2, 3] as const).map((n) => ({ q: t(`portalPlus.travel.q${n}`), a: t(`portalPlus.travel.a${n}`) }))} />
        <CtaBand title={t("portalPlus.travel.ctaTitle")} action={{ label: t("portalPlus.travel.ctaButton"), href: register }} />
      </LandingPage>
    </>
  );
}
