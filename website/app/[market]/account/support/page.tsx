import { SupportList } from "@/features/support/support";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("accountPlus.meta.support");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <SupportList />;
}
