import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { NewReturnForm } from "@/features/account/returns";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("meta.newReturn");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function NewReturnPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <NewReturnForm />
    </Suspense>
  );
}
