import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { SocialComplete } from "@/features/auth/social-complete";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

type Props = { params: Promise<{ market: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("auth.socialCompleteTitle"), robots: { index: false, follow: false } };
}

/**
 * `/auth/social/complete?ticket=…&return_to=…` (D9): the API redirects here
 * after Google / Apple. The one-time ticket is exchanged through the BFF, which
 * keeps the token in its httpOnly cookie.
 */
export default function SocialCompletePage({ searchParams }: Props) {
  return (
    <Container className="py-12">
      <Suspense fallback={<Skeleton className="mx-auto h-64 max-w-md" />}>
        {searchParams.then((raw) => {
          const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
          const ticket = one(raw.ticket)?.trim() || null;
          return <SocialComplete ticket={ticket && ticket.length <= 100 ? ticket : null} returnTo={one(raw.return_to) ?? null} />;
        })}
      </Suspense>
    </Container>
  );
}
