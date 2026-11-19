import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { DisputeDetail } from "@/features/account-extra/disputes";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("meta.disputeDetail");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page({ params }: PageProps<"/[market]/account/disputes/[number]">) {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      {params.then(({ number }) => (
        <DisputeDetail number={decodeURIComponent(number)} />
      ))}
    </Suspense>
  );
}
