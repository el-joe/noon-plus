import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { BlogList } from "@/features/content/blog";
import { flatParams } from "@/features/content/pagination";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/blog">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("content.blog.title") };
}

/** `/blog` (Figma Blog List 100:4258). */
export default async function BlogPage({ params, searchParams }: PageProps<"/[market]/blog">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {searchParams.then((raw) => (
          <BlogList market={market} query={flatParams(raw)} />
        ))}
      </Suspense>
    </Container>
  );
}
