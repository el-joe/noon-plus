import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { BlogArticle } from "@/features/content/blog";
import { orNull } from "@/lib/api/client";
import { getBlogPost } from "@/lib/api/content";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/blog/[slug]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const { slug } = await params;
  const post = await orNull(getBlogPost(market.slug, decodeURIComponent(slug)).then((r) => r.data)).catch(() => null);
  if (!post) return {};
  return {
    title: post.title ?? undefined,
    description: post.excerpt ?? undefined,
    openGraph: { title: post.title ?? undefined, description: post.excerpt ?? undefined, type: "article", images: post.cover_url ? [post.cover_url] : undefined },
  };
}

/** `/blog/{slug}` (Figma Blog Post 100:4552). */
async function BlogPostPageContent({ params }: PageProps<"/[market]/blog/[slug]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {params.then(({ slug }) => (
          <BlogArticle market={market} slug={decodeURIComponent(slug)} />
        ))}
      </Suspense>
    </Container>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function BlogPostPage(props: PageProps<"/[market]/blog/[slug]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <BlogPostPageContent {...props} />
    </Suspense>
  );
}
