import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { PortalNav } from "@/features/portal/portal-nav";
import { ApplicationStatusView } from "@/features/portal/status-view";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/join/status">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("portal.status.title"), robots: { index: false } };
}

/** `/join/status[?number=]` — application status (Figma 73:1273, 100:18238, 100:18417, 100:18594). */
export default async function StatusPage({ params, searchParams }: PageProps<"/[market]/join/status">) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return (
    <>
      <PortalNav market={market} active="status" />
      <Container className="max-w-3xl py-8">
        <h1 className="mb-6 text-h1 font-semibold">{t("portal.status.title")}</h1>
        <Suspense fallback={<Skeleton className="h-96 w-full" />}>
          {searchParams.then((query) => {
            const number = Array.isArray(query.number) ? query.number[0] : query.number;
            return <ApplicationStatusView key={number ?? "all"} number={number} />;
          })}
        </Suspense>
      </Container>
    </>
  );
}
