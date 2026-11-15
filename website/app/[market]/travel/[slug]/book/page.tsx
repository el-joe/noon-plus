import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { BookingForm } from "@/features/travel/booking-form";
import type { MarketParams, SearchParams } from "@/features/travel/page-types";
import { orNull } from "@/lib/api/client";
import { getTravelPackage } from "@/lib/api/travel";
import { getT } from "@/lib/i18n";
import { parseMarket } from "@/lib/markets";

type Props = { params: MarketParams<{ slug: string }>; searchParams: SearchParams };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const market = parseMarket((await params).market);
  return { title: market ? getT(market.locale)("travel.book.title") : undefined, robots: { index: false } };
}

/** `/travel/[slug]/book?departure=&room=&travellers=` — booking request form (D8, no payment). */
export default function BookTripPage({ params, searchParams }: Props) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[720px] w-full" />}>
        <BookContent params={params} searchParams={searchParams} />
      </Suspense>
    </Container>
  );
}

async function BookContent({ params, searchParams }: Props) {
  const { market: slug, slug: tripSlug } = await params;
  const market = parseMarket(slug);
  if (!market) notFound();
  const raw = await searchParams;
  const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
  const pkg = await orNull(getTravelPackage(market.slug, tripSlug).then((r) => r.data));
  if (!pkg) notFound();
  return (
    <BookingForm
      pkg={pkg}
      initial={{ departure: Number(one(raw.departure)) || undefined, room: one(raw.room) || undefined, travellers: Number(one(raw.travellers)) || undefined }}
    />
  );
}
