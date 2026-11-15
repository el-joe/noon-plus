import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { ClassifiedAdDetail } from "@/features/classifieds/ad-detail";
import * as api from "@/lib/api/classifieds";
import { orNull } from "@/lib/api/client";
import { parseMarket } from "@/lib/markets";

type Props = PageProps<"/[market]/classified/find/[number]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { market: slug, number } = await params;
  const market = parseMarket(slug);
  if (!market) return {};
  const ad = await orNull(api.getAd(market.slug, decodeURIComponent(number)).then((r) => r.data)).catch(() => null);
  return ad ? { title: ad.title, description: ad.description?.slice(0, 160) ?? undefined } : {};
}

/** `/classified/find/[number]` — ad detail (Figma 55:1477). */
export default function ClassifiedAdPage({ params }: Props) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[720px] w-full" />}>
        <AdContent params={params} />
      </Suspense>
    </Container>
  );
}

async function AdContent({ params }: { params: Props["params"] }) {
  const { market: slug, number } = await params;
  const market = parseMarket(slug);
  if (!market) notFound();
  const ad = await orNull(api.getAd(market.slug, decodeURIComponent(number)).then((r) => r.data));
  if (!ad) notFound();
  return <ClassifiedAdDetail market={market} ad={ad} />;
}
