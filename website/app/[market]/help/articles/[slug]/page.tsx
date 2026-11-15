import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { HELP_CENTER, HelpArticleView } from "@/features/content/help";
import { orNull } from "@/lib/api/client";
import { getHelpArticle } from "@/lib/api/content";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/help/articles/[slug]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const { slug } = await params;
  const article = await orNull(getHelpArticle(market.slug, HELP_CENTER.hub, decodeURIComponent(slug)).then((r) => r.data)).catch(() => null);
  const hub = getT(market.locale)("content.help.title");
  return { title: article?.title ? `${article.title} — ${hub}` : hub };
}

async function HubArticlePageContent({ params }: PageProps<"/[market]/help/articles/[slug]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[480px] w-full" />}>
        {params.then(({ slug }) => (
          <HelpArticleView market={market} config={HELP_CENTER} slug={decodeURIComponent(slug)} />
        ))}
      </Suspense>
    </Container>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function HubArticlePage(props: PageProps<"/[market]/help/articles/[slug]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <HubArticlePageContent {...props} />
    </Suspense>
  );
}
