import type { Metadata } from "next";
import { Suspense } from "react";
import { AuthShell } from "@/components/layout/auth-shell";
import { Skeleton } from "@/components/ui/misc";
import { AddEmailForm } from "@/features/auth/add-email-form";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/auth/add-email">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("auth.addEmailTitle"), robots: { index: false, follow: false } };
}

/** `/auth/add-email?next=…` — an old phone-only Nawy account adds and confirms its email after the SMS-code sign-in. */
export default async function AddEmailPage({ params }: PageProps<"/[market]/auth/add-email">) {
  const market = await resolveMarket(params);
  return (
    <AuthShell locale={market.locale}>
      <Suspense fallback={<Skeleton className="h-80 w-full max-w-md" />}>
        <AddEmailForm />
      </Suspense>
    </AuthShell>
  );
}
