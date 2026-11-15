import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { ReturnDetail } from "@/features/account-extra/return-detail";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page({ params }: PageProps<"/[market]/account/returns/[number]">) {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      {params.then(({ number }) => (
        <ReturnDetail number={decodeURIComponent(number)} />
      ))}
    </Suspense>
  );
}
