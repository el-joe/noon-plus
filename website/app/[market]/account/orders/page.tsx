import { OrdersList } from "@/features/account/orders";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.orders");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function OrdersPage() {
  return <OrdersList />;
}
