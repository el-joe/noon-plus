import { EmailPreferencesView } from "@/features/account-extra/email-preferences";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("accountExtra.emailPreferences.title");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <EmailPreferencesView />;
}
