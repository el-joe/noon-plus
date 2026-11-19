import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { TicketDetail } from "@/features/support/support";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("accountPlus.meta.ticket");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page({ params }: { params: Promise<{ market: string; number: string }> }) {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      {params.then(({ number }) => (
        <TicketDetail number={decodeURIComponent(number)} />
      ))}
    </Suspense>
  );
}
