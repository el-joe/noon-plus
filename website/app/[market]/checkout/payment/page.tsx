import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { PaymentResult } from "@/features/checkout/payment-result";

export const metadata: Metadata = { robots: { index: false } };

/** Gateway return URL (see PaymentController::toStorefront). */
export default function PaymentReturnPage() {
  return (
    <Container className="py-10">
      <Suspense fallback={<Skeleton className="mx-auto h-72 max-w-xl" />}>
        <PaymentResult />
      </Suspense>
    </Container>
  );
}
