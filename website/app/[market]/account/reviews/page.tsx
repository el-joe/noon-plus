import { MyReviewsView } from "@/features/account/my-reviews";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.reviews");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function MyReviewsPage() {
  return <MyReviewsView />;
}
