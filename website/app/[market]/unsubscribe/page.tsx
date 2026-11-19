import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { UnsubscribeView } from "@/features/account-extra/email-preferences";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

type Props = { params: Promise<{ market: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("accountExtra.unsubscribe.title"), robots: { index: false } };
}

/** `/unsubscribe?token=…` — one-click unsubscribe landing from reminder / alert emails (signed token, no sign-in). */
export default function UnsubscribePage({ searchParams }: Props) {
  return (
    <Container className="py-12">
      <Suspense fallback={<Skeleton className="mx-auto h-64 max-w-md" />}>
        {searchParams.then((raw) => {
          const value = Array.isArray(raw.token) ? raw.token[0] : raw.token;
          const token = value?.trim() || null;
          return <UnsubscribeView token={token && token.length <= 600 && /^[A-Za-z0-9_.-]+$/.test(token) ? token : null} />;
        })}
      </Suspense>
    </Container>
  );
}
