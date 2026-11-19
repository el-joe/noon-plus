import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { AddEmailForm } from "@/features/auth/add-email-form";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/auth/add-email">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("auth.addEmailTitle"), robots: { index: false, follow: false } };
}

/** `/auth/add-email?next=…` — an old phone-only Nawy account adds and confirms its email after the SMS-code sign-in. */
export default function AddEmailPage() {
  return (
    <Container className="py-12">
      <Suspense fallback={<Skeleton className="mx-auto h-80 max-w-md" />}>
        <AddEmailForm />
      </Suspense>
    </Container>
  );
}
