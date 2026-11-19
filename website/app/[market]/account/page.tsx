import { AccountOverview } from "@/features/account/overview";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.myAccount");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function AccountPage() {
  return <AccountOverview />;
}
