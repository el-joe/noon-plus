import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { WarrantyClaimDetail } from "@/features/account-extra/warranty";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page({ params }: PageProps<"/[market]/account/warranty/[number]">) {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      {params.then(({ number }) => (
        <WarrantyClaimDetail number={decodeURIComponent(number)} />
      ))}
    </Suspense>
  );
}
