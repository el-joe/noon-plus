import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { InquiriesView } from "@/features/classifieds/account";

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <InquiriesView />
    </Suspense>
  );
}
