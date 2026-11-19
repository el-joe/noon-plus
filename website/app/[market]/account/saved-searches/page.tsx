import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { SavedSearchesView } from "@/features/classifieds/account";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("account.nav.savedSearches");

// Rendered by the session-gated AccountShell (see the account layout).
export const instant = false;

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <SavedSearchesView />
    </Suspense>
  );
}
