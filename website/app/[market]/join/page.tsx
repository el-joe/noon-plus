import type { Metadata } from "next";
import { Suspense } from "react";
import { Alert, Container, Skeleton } from "@/components/ui/misc";
import { JoinChooser } from "@/features/portal/join-chooser";
import { PortalNav } from "@/features/portal/portal-nav";
import { getJoinOptions } from "@/lib/api/portal";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/join">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("portal.join.metaTitle"), description: t("portal.join.subtitle") };
}

/** `/join` — join chooser (Figma Join chooser v2 101:16699) from `GET join-options` of the market's country. */
export default async function JoinPage({ params }: PageProps<"/[market]/join">) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return (
    <>
      <PortalNav market={market} active="join" />
      <Container className="py-8">
        <div className="mb-6 text-center">
          <p className="text-caption font-semibold uppercase tracking-wide text-brand-text">{t("portal.join.eyebrow")}</p>
          <h1 className="mt-2 text-h1 font-semibold">{t("portal.join.title")}</h1>
          <p className="mt-2 text-body text-secondary">{t("portal.join.subtitle")}</p>
        </div>
        <Suspense fallback={<Skeleton className="h-[520px] w-full" />}>
          <Options market={market} />
        </Suspense>
      </Container>
    </>
  );
}

async function Options({ market }: { market: Market }) {
  const t = getT(market.locale);
  const options = await getJoinOptions(market.slug)
    .then((r) => r.data)
    .catch(() => null);
  if (!options) return <Alert tone="danger">{t("portal.common.error")}</Alert>;
  return <JoinChooser options={options} />;
}
