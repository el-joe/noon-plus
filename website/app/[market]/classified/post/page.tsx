import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { PostAdWizard } from "@/features/classifieds/post-wizard";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/classified/post">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("classifieds.post.title"), robots: { index: false } };
}

// The wizard needs the browser session (signed-in owners only).
export const instant = false;

/** `/classified/post[?number=]` — post-ad wizard (Figma 55:2016). */
export default function PostAdPage({ searchParams }: PageProps<"/[market]/classified/post">) {
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-96 w-full" />}>
        {searchParams.then((raw) => {
          const number = Array.isArray(raw.number) ? raw.number[0] : raw.number;
          return <PostAdWizard key={number ?? "new"} number={number || undefined} />;
        })}
      </Suspense>
    </Container>
  );
}
