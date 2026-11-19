import { SavedCardsView } from "@/features/account/saved-cards";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("accountPlus.meta.cards");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <SavedCardsView />;
}
