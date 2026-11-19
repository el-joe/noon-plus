import { SpecialRequests } from "@/features/real-estate/special-requests";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.specialRequests");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

/** `/account/special-requests` (Figma 50:2886). */
export default function SpecialRequestsPage() {
  return <SpecialRequests />;
}
