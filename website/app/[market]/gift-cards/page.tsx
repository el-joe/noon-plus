import type { Metadata } from "next";
import { Container } from "@/components/ui/misc";
import { GiftCardsPage } from "@/features/shop/gift-cards";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/gift-cards">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("shop.giftCards.metaTitle"), description: t("shop.giftCards.subtitle") };
}

/** `/gift-cards` (Figma Gift cards 71:11244). */
export default function GiftCards() {
  return (
    <Container className="py-4">
      <GiftCardsPage />
    </Container>
  );
}
