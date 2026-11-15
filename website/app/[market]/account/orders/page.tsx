import { OrdersList } from "@/features/account/orders";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function OrdersPage() {
  return <OrdersList />;
}
