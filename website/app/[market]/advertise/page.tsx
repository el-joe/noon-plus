import type { Metadata } from "next";
import { Megaphone } from "lucide-react";
import { Container } from "@/components/ui/misc";
import { AdvertiseForm } from "@/features/portal/advertise-form";
import { PortalNav } from "@/features/portal/portal-nav";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/advertise">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("portal.advertise.title"), description: t("portal.advertise.subtitle") };
}

/** `/advertise` — talk to the Nawy Ads team (Figma Advertise Request form 96:8534). */
export default async function AdvertisePage({ params }: PageProps<"/[market]/advertise">) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  const steps = ["review", "call", "plan"] as const;
  return (
    <>
      <PortalNav market={market} active="advertise" />
      <Container className="py-8">
        <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-subtle px-2.5 py-0.5 text-caption font-semibold text-brand-text">
          <Megaphone className="size-3.5" />
          {t("portal.advertise.eyebrow")}
        </span>
        <h1 className="mt-3 text-h1 font-semibold">{t("portal.advertise.title")}</h1>
        <p className="mt-2 text-body text-secondary">{t("portal.advertise.subtitle")}</p>
        <div className="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
          <AdvertiseForm />
          <aside className="flex flex-col gap-4">
            <section className="rounded-lg border border-line bg-surface p-5">
              <h2 className="mb-3 text-h4 font-semibold">{t("portal.advertise.nextTitle")}</h2>
              <ol className="flex flex-col gap-3">
                {steps.map((step, i) => (
                  <li key={step} className="flex items-start gap-3">
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-brand-subtle text-body-sm font-semibold text-brand-text">{i + 1}</span>
                    <span>
                      <span className="block text-body-sm font-semibold">{t(`portal.advertise.next.${step}.title`)}</span>
                      <span className="block text-caption text-secondary">{t(`portal.advertise.next.${step}.body`)}</span>
                    </span>
                  </li>
                ))}
              </ol>
            </section>
            <section className="rounded-lg border border-line bg-surface p-5">
              <h2 className="mb-1 text-h4 font-semibold">{t("portal.advertise.sellersTitle")}</h2>
              <p className="text-body-sm text-secondary">{t("portal.advertise.sellersBody")}</p>
            </section>
          </aside>
        </div>
      </Container>
    </>
  );
}
