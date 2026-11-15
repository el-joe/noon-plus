import { ProfileView } from "@/features/account-extra/profile";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <ProfileView />;
}
