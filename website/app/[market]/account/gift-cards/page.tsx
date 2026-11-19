import { AccountGiftCards } from "@/features/shop/gift-cards";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.giftCards");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <AccountGiftCards />;
}
