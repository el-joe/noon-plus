import { NewWarrantyClaim } from "@/features/account-extra/warranty";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("meta.newWarranty");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <NewWarrantyClaim />;
}
