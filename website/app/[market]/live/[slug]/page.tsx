import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { Crumbs } from "@/features/content/pagination";
import { WatchView } from "@/features/live/watch-view";
import { orNull } from "@/lib/api/client";
import { getStream } from "@/lib/api/live";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/live/[slug]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const { slug } = await params;
  const stream = await orNull(getStream(market.slug, decodeURIComponent(slug)).then((r) => r.data)).catch(() => null);
  if (!stream) return {};
  return { title: stream.title ?? undefined, description: stream.description ?? undefined, openGraph: { images: stream.thumbnail_url ? [stream.thumbnail_url] : undefined } };
}

/** `/live/{slug}` — watch page (Figma Live stream player 100:12627). */
async function WatchPageContent({ params }: PageProps<"/[market]/live/[slug]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="aspect-video w-full" />}>
        {params.then(({ slug }) => (
          <Watch market={market} slug={decodeURIComponent(slug)} />
        ))}
      </Suspense>
    </Container>
  );
}

async function Watch({ market, slug }: { market: Market; slug: string }) {
  const stream = await orNull(getStream(market.slug, slug).then((r) => r.data));
  if (!stream) notFound();
  const t = getT(market.locale);
  return (
    <div className="flex flex-col gap-4">
      <Crumbs market={market} items={[{ label: t("live.title"), href: `/${market.slug}/live` }, { label: stream.title ?? slug }]} />
      <WatchView stream={stream} />
    </div>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function WatchPage(props: PageProps<"/[market]/live/[slug]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <WatchPageContent {...props} />
    </Suspense>
  );
}
