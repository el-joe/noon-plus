import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { firstParam } from "@/features/content/pagination";
import { LiveList } from "@/features/live/live-list";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/live">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("live.title"), description: t("live.subtitle") };
}

const TABS = ["all", "live", "upcoming", "replays"] as const;

/** `/live` — Nawy TV (Figma 59:4518). */
export default async function LivePage({ params, searchParams }: PageProps<"/[market]/live">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {searchParams.then((raw) => {
          const tab = TABS.find((k) => k === firstParam(raw.tab)) ?? "all";
          return <LiveList market={market} tab={tab} />;
        })}
      </Suspense>
    </Container>
  );
}
