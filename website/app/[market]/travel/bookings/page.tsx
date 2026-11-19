import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { MyTravelBookings } from "@/features/travel/my-bookings";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("meta.travelBookings");
export const instant = false;

/** `/travel/bookings` — guest-friendly list of trip requests on this device + find a booking by number and access code. */
export default function TravelBookingsPage() {
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-96 w-full" />}>
        <MyTravelBookings withFind />
      </Suspense>
    </Container>
  );
}
