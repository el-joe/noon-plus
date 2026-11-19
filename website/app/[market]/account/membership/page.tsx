import { MembershipPanel } from "@/features/shop/membership";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.membership");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <MembershipPanel showTitle />;
}
