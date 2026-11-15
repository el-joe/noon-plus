import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { ConfirmationView } from "@/features/checkout/confirmation-view";

export const metadata: Metadata = { robots: { index: false } };

export default function OrderConfirmationPage() {
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="mx-auto h-96 max-w-2xl" />}>
        <ConfirmationView />
      </Suspense>
    </Container>
  );
}
