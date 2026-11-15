import type { Metadata } from "next";
import { Container } from "@/components/ui/misc";
import { MembershipLanding } from "@/features/shop/membership";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/membership">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("shop.membership.metaTitle"), description: t("shop.membership.subtitle") };
}

/** `/membership` — Nawy One (pattern: app frame 99:6173). */
export default function MembershipPage() {
  return (
    <Container className="py-4">
      <MembershipLanding />
    </Container>
  );
}
