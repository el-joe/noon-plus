import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { ClassifiedsHome } from "@/features/classifieds/home";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/classified">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("classifieds.title"), description: t("classifieds.home.subtitle") };
}

/** `/classified` — Open Souq home (Figma 54:2). */
export default async function ClassifiedHomePage({ params }: PageProps<"/[market]/classified">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <ClassifiedsHome market={market} />
      </Suspense>
    </Container>
  );
}
