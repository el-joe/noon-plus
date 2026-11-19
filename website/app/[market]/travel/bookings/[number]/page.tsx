import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { BookingStatusView } from "@/features/travel/booking-status";
import type { MarketParams } from "@/features/travel/page-types";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("meta.travelBooking");

// The booking is read in the browser (guest token cookie or session), so navigations may wait.
export const instant = false;

/** `/travel/bookings/[number]` — booking status (Figma 98:10830); `?token=` opens a guest booking from its link. */
export default function TravelBookingPage({ params }: { params: MarketParams<{ number: string }> }) {
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[560px] w-full" />}>
        {params.then(({ number }) => (
          <BookingStatusView number={decodeURIComponent(number)} />
        ))}
      </Suspense>
    </Container>
  );
}
