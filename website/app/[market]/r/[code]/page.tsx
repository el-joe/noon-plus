import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { ReferralLanding } from "@/features/shop/referral";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/r/[code]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("shop.referral.metaTitle"), robots: { index: false } };
}

/** `/r/{code}` — referral landing (Figma 71:11488); the click is recorded from the browser via the BFF proxy. */
export default function ReferralPage({ params }: PageProps<"/[market]/r/[code]">) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-64 w-full" />}>
        {params.then(({ code }) => {
          const value = decodeURIComponent(code);
          if (!/^[A-Za-z0-9]{6,16}$/.test(value)) notFound();
          return <ReferralLanding code={value} />;
        })}
      </Suspense>
    </Container>
  );
}
