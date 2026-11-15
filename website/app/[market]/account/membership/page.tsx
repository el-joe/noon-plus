import { MembershipPanel } from "@/features/shop/membership";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return <MembershipPanel showTitle />;
}
