import { ReturnsList } from "@/features/account/returns";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.returns");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function ReturnsPage() {
  return <ReturnsList />;
}
