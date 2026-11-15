import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { HELP_CENTER, HelpCollectionView } from "@/features/content/help";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/help/collections/[slug]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("content.help.title") };
}

async function HubCollectionPageContent({ params }: PageProps<"/[market]/help/collections/[slug]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[480px] w-full" />}>
        {params.then(({ slug }) => (
          <HelpCollectionView market={market} config={HELP_CENTER} slug={decodeURIComponent(slug)} />
        ))}
      </Suspense>
    </Container>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function HubCollectionPage(props: PageProps<"/[market]/help/collections/[slug]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <HubCollectionPageContent {...props} />
    </Suspense>
  );
}
