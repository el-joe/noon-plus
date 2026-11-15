import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { AccountShell } from "@/features/account/account-shell";

export const metadata: Metadata = { robots: { index: false } };

// The account shell renders its pages only once the session is known in the
// browser, so navigations into /account are allowed to wait for it.
export const instant = false;

/** `/account/*` — customer account (data via the BFF proxy with the session cookie). */
export default function AccountLayout({ children }: LayoutProps<"/[market]/account">) {
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[480px] w-full" />}>
        <AccountShell>{children}</AccountShell>
      </Suspense>
    </Container>
  );
}
