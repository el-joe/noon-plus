import type { Metadata } from "next";
import { Suspense } from "react";
import { AuthShell } from "@/components/layout/auth-shell";
import { Skeleton } from "@/components/ui/misc";
import { SignInForm } from "@/features/auth/sign-in-form";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/login">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("header.signIn"), robots: { index: false } };
}

/** `/login?next=…` — email + 6-digit code (Figma Login 28:2, AR 84:2, OTP 28:107), inside the store header and footer. */
export default async function LoginPage({ params }: PageProps<"/[market]/login">) {
  const market = await resolveMarket(params);
  return (
    <AuthShell locale={market.locale}>
      <Suspense fallback={<Skeleton className="h-96 w-full max-w-md" />}>
        <SignInForm />
      </Suspense>
    </AuthShell>
  );
}
