import type { Metadata } from "next";
import { Container } from "@/components/ui/misc";
import { CheckoutView } from "@/features/checkout/checkout-view";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/checkout">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("checkout.title"), robots: { index: false } };
}

export default function CheckoutPage() {
  return (
    <Container className="py-6">
      <CheckoutView />
    </Container>
  );
}
