import { SecurityView } from "@/features/account/security";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.security");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function SecurityPage() {
  return <SecurityView />;
}
