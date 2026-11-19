import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { NewTicket } from "@/features/support/support";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("accountPlus.meta.newTicket");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <NewTicket />
    </Suspense>
  );
}
