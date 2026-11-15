import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { NewsletterConfirm } from "@/features/newsletter/newsletter-confirm";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

type Props = { params: Promise<{ market: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("newsletter.confirmTitle"), robots: { index: false } };
}

/** `/newsletter/confirm?token=…[&action=unsubscribe]` — the link in the double opt-in email (G-W12). */
export default function NewsletterConfirmPage({ searchParams }: Props) {
  return (
    <Container className="py-12">
      <Suspense fallback={<Skeleton className="mx-auto h-64 max-w-md" />}>
        {searchParams.then((raw) => {
          const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
          const token = one(raw.token)?.trim() || null;
          return <NewsletterConfirm token={token && token.length <= 128 ? token : null} action={one(raw.action) === "unsubscribe" ? "unsubscribe" : "confirm"} />;
        })}
      </Suspense>
    </Container>
  );
}
