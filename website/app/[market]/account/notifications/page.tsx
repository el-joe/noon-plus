import { NotificationsView } from "@/features/account-extra/notifications";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <NotificationsView />;
}
