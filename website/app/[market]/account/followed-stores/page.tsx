import { FollowedStores } from "@/features/account/followed-stores";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("accountPlus.meta.followed");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <FollowedStores />;
}
