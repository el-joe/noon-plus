import type { Metadata } from "next";
import { Suspense } from "react";
import { AuthShell } from "@/components/layout/auth-shell";
import { Skeleton } from "@/components/ui/misc";
import { ResetPasswordForm } from "@/features/auth/reset-password-form";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

type Props = { params: Promise<{ market: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("auth.forgotTitle"), robots: { index: false, follow: false } };
}

/**
 * `/reset-password` (D9): `?email=…&token=…` from the reset email (link), or
 * the email + 6-digit code from the same email (code).
 */
export default async function ResetPasswordPage({ params, searchParams }: Props) {
  const market = await resolveMarket(params);
  return (
    <AuthShell locale={market.locale}>
      <Suspense fallback={<Skeleton className="h-80 w-full max-w-md" />}>
        {searchParams.then((raw) => {
          const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
          const token = one(raw.token)?.trim() || null;
          return <ResetPasswordForm email={(one(raw.email) ?? "").slice(0, 255)} token={token && token.length <= 255 ? token : null} />;
        })}
      </Suspense>
    </AuthShell>
  );
}
