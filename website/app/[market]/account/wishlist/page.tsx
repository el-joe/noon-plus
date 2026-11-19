import { WishlistView } from "@/features/account/wishlist";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.wishlist");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function WishlistPage() {
  return <WishlistView />;
}
