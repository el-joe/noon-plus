import { Suspense } from "react";
import { CheckoutChrome } from "@/features/checkout/checkout-chrome";
import { Container, Skeleton } from "@/components/ui/misc";
import { PaymentResult } from "@/features/checkout/payment-result";
import { privatePageMetadata } from "@/lib/server-market";

export const generateMetadata = privatePageMetadata("meta.checkoutPayment");

/** Gateway return URL (see PaymentController::toStorefront). */
export default function PaymentReturnPage() {
  return (
    <>
      <CheckoutChrome />
      <Container className="py-10">
        <Suspense fallback={<Skeleton className="mx-auto h-72 max-w-xl" />}>
          <PaymentResult />
        </Suspense>
      </Container>
    </>
  );
}
