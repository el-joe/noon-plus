import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { BookingSent } from "@/features/travel/booking-status";
import type { MarketParams } from "@/features/travel/page-types";

export const metadata: Metadata = { robots: { index: false } };
export const instant = false;

/** `/travel/bookings/[number]/sent` — booking request sent (Figma 96:4554, D8). */
export default function TravelBookingSentPage({ params }: { params: MarketParams<{ number: string }> }) {
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-96 w-full" />}>
        {params.then(({ number }) => (
          <BookingSent number={decodeURIComponent(number)} />
        ))}
      </Suspense>
    </Container>
  );
}
