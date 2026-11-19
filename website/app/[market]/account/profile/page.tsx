import { ProfileView } from "@/features/account-extra/profile";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.profile");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <ProfileView />;
}
