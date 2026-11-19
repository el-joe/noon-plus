import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { StaticPageView } from "@/features/content/static-page";
import { orNull } from "@/lib/api/client";
import { getStaticPage } from "@/lib/api/content";
import { resolveMarket } from "@/lib/server-market";
import { withoutBrandSuffix } from "@/lib/format";

export async function generateMetadata({ params }: PageProps<"/[market]/pages/[slug]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const { slug } = await params;
  const page = await orNull(getStaticPage(market.slug, decodeURIComponent(slug)).then((r) => r.data)).catch(() => null);
  if (!page) return {};
  // `seo` is admin-set per language, with the API's fallback (title, first 160 characters of the body).
  return { title: withoutBrandSuffix(page.seo?.title || page.title) || undefined, description: page.seo?.description || undefined, alternates: { canonical: `/${market.slug}/pages/${encodeURIComponent(page.slug)}` } };
}

/** `/pages/{slug}` — about, contact, terms, privacy, returns … (Admin → Content → Static pages). */
async function StaticPageContent({ params }: PageProps<"/[market]/pages/[slug]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[480px] w-full" />}>
        {params.then(({ slug }) => (
          <StaticPageView market={market} slug={decodeURIComponent(slug)} />
        ))}
      </Suspense>
    </Container>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function StaticPage(props: PageProps<"/[market]/pages/[slug]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <StaticPageContent {...props} />
    </Suspense>
  );
}
