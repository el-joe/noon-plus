import { AccountOverview } from "@/features/account/overview";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function AccountPage() {
  return <AccountOverview />;
}
