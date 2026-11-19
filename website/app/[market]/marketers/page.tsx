import type { Metadata } from "next";
import { Handshake, Megaphone, Percent } from "lucide-react";
import Link from "next/link";
import { PortalNav } from "@/features/portal/portal-nav";
import { LandingHero, LandingPage } from "@/features/portal/landing";
import { MARKETER_ROLES } from "@/features/portal/marketer-roles";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/marketers">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("portalPlus.marketers.metaTitle"), description: t("portalPlus.marketers.subtitle") };
}

const ICONS = { influencer: Megaphone, affiliate: Percent, broker: Handshake } as const;

/** `/marketers` — marketers overview (Figma 93:1791, QC-042): one card per marketer type. */
export default async function MarketersPage({ params }: PageProps<"/[market]/marketers">) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  const base = `/${market.slug}`;
  return (
    <>
      <PortalNav market={market} active="marketers" />
      <LandingPage>
        <LandingHero
          tone="light"
          eyebrow={t("portalPlus.marketers.eyebrow")}
          title={t("portalPlus.marketers.title")}
          body={t("portalPlus.marketers.subtitle")}
          primary={{ label: t("portalPlus.marketers.chooseType"), href: `${base}/marketers#types` }}
          secondary={{ label: t("portalPlus.marketers.contracts"), href: `${base}/legal/seller-agreement?agreement=marketer_onboarding` }}
        />
        <section id="types" className="grid scroll-mt-32 gap-3 md:grid-cols-3">
          {MARKETER_ROLES.map((role) => {
            const Icon = ICONS[role];
            return (
              <Link key={role} href={`${base}/marketers/${role}`} className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-5 hover:shadow-sm">
                <span className="flex size-9 items-center justify-center rounded-md bg-brand-subtle">
                  <Icon className="size-5 text-brand-text" />
                </span>
                <span className="text-body font-semibold">{t(`portalPlus.roles.${role}.name`)}</span>
                <span className="text-body-sm text-secondary">{t(`portalPlus.roles.${role}.summary`)}</span>
                <span className="self-start rounded-full bg-brand-subtle px-2 py-0.5 text-caption font-medium">{t(`portal.roles.${role}.hint`)}</span>
              </Link>
            );
          })}
        </section>
      </LandingPage>
    </>
  );
}
