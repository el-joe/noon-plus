import { CreditsView } from "@/features/account/credits";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.credits");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function CreditsPage() {
  return <CreditsView />;
}
