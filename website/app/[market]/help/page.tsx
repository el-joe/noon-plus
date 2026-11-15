import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { HELP_CENTER, HelpHome } from "@/features/content/help";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/help">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("content.help.title") };
}

export default async function HubHomePage({ params }: PageProps<"/[market]/help">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <HelpHome market={market} config={HELP_CENTER} />
      </Suspense>
    </Container>
  );
}
