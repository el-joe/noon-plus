import { AddressesView } from "@/features/account/addresses";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.addresses");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function AddressesPage() {
  return <AddressesView />;
}
