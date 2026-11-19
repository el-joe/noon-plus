import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { OrderDetail } from "@/features/account/order-detail";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("meta.orderDetail");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function OrderPage({ params }: PageProps<"/[market]/account/orders/[number]">) {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      {params.then(({ number }) => (
        <OrderDetail number={decodeURIComponent(number)} />
      ))}
    </Suspense>
  );
}
