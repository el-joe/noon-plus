import { MyTravelBookings } from "@/features/travel/my-bookings";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.bookings");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

/** `/account/bookings` — My bookings (Figma 49:2757). */
export default function AccountBookingsPage() {
  return <MyTravelBookings />;
}
