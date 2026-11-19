import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
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
export default function ResetPasswordPage({ searchParams }: Props) {
  return (
    <Container className="flex justify-center py-12">
      <Suspense fallback={<Skeleton className="h-96 w-full max-w-md" />}>
        {searchParams.then((raw) => {
          const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
          const token = one(raw.token)?.trim() || null;
          return <ResetPasswordForm email={(one(raw.email) ?? "").slice(0, 255)} token={token && token.length <= 255 ? token : null} />;
        })}
      </Suspense>
    </Container>
  );
}
