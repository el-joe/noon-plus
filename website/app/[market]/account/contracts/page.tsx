import { ContractsView } from "@/features/account-extra/contracts";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.contracts");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <ContractsView />;
}
