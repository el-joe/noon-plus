import type { Metadata } from "next";
import { Container } from "@/components/ui/misc";
import { CartView } from "@/features/cart/cart-view";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/cart">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("cart.title"), robots: { index: false } };
}

export default function CartPage() {
  return (
    <Container className="py-6">
      <CartView />
    </Container>
  );
}
