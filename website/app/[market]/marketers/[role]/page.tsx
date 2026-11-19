import type { Metadata } from "next";
import { BadgeCheck, CalendarCheck, FileSignature, Gift, Handshake, Link2, Percent, Users, Wallet } from "lucide-react";
import { notFound } from "next/navigation";
import { PortalNav } from "@/features/portal/portal-nav";
import { CtaBand, Faq, FeatureGrid, LandingHero, LandingPage } from "@/features/portal/landing";
import { isMarketerRole, MARKETER_REGISTER_PATH, MARKETER_ROLES, type MarketerRole } from "@/features/portal/marketer-roles";
import { FunnelView } from "@/features/portal/funnel";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

// Unknown roles 404 from the param, so the route may block on it.
export const instant = false;

export function generateStaticParams() {
  return MARKETER_ROLES.map((role) => ({ role }));
}

export async function generateMetadata({ params }: PageProps<"/[market]/marketers/[role]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const { role } = await params;
  if (!isMarketerRole(role)) return {};
  const t = getT(market.locale);
  return { title: t(`portalPlus.roles.${role}.title`), description: t(`portalPlus.roles.${role}.summary`) };
}

const EARN_ICONS: Record<MarketerRole, [typeof Users, typeof Users, typeof Users, typeof Users]> = {
  influencer: [Users, FileSignature, Gift, Wallet],
  affiliate: [Link2, Percent, CalendarCheck, Wallet],
  broker: [Handshake, BadgeCheck, FileSignature, Wallet],
};

/** `/marketers/{influencer|affiliate|broker}` (Figma 93:1939 / 93:2100 / 93:2261, QC-042). */
export default async function MarketerRolePage({ params }: PageProps<"/[market]/marketers/[role]">) {
  const market = await resolveMarket(params);
  const { role } = await params;
  if (!isMarketerRole(role)) notFound();
  const t = getT(market.locale);
  const base = `/${market.slug}`;
  const register = `${base}${MARKETER_REGISTER_PATH[role]}`;
  const earn = ([1, 2, 3, 4] as const).map((n, i) => ({ icon: EARN_ICONS[role][i], title: t(`portalPlus.roles.${role}.earn${n}`) }));
  const faq = ([1, 2, 3] as const).map((n) => ({ q: t(`portalPlus.roles.${role}.q${n}`), a: t(`portalPlus.roles.${role}.a${n}`) }));
  return (
    <>
      <PortalNav market={market} active="marketers" />
      <FunnelView page={`marketers/${role}`} />
      <LandingPage>
        <LandingHero
          eyebrow={t(`portalPlus.roles.${role}.name`)}
          title={t(`portalPlus.roles.${role}.title`)}
          body={t(`portal.roles.${role}.hint`)}
          primary={{ label: t(`portalPlus.roles.${role}.register`), href: register }}
          secondary={{ label: t("portalPlus.marketers.seeAll"), href: `${base}/marketers` }}
        />
        <FeatureGrid title={t("portalPlus.marketers.howYouEarn")} items={earn} columns={4} />
        <Faq title={t("portalPlus.common.questions")} items={faq} />
        <CtaBand title={t(`portalPlus.roles.${role}.cta`)} action={{ label: t("portalPlus.common.register"), href: register }} />
      </LandingPage>
    </>
  );
}
