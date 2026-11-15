import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { BlogList } from "@/features/content/blog";
import { flatParams } from "@/features/content/pagination";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/blog/category/[slug]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("content.blog.title") };
}

/** `/blog/category/{slug}` — the blog list filtered by one category. */
async function BlogCategoryPageContent({ params, searchParams }: PageProps<"/[market]/blog/category/[slug]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {Promise.all([params, searchParams]).then(([{ slug }, raw]) => (
          <BlogList market={market} query={{ ...flatParams(raw), category: decodeURIComponent(slug) }} />
        ))}
      </Suspense>
    </Container>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function BlogCategoryPage(props: PageProps<"/[market]/blog/category/[slug]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <BlogCategoryPageContent {...props} />
    </Suspense>
  );
}
