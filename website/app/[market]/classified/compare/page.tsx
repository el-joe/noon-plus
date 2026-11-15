import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { ClassifiedCompare } from "@/features/classifieds/compare";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/classified/compare">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("classifieds.compare.title"), robots: { index: false } };
}

/** `/classified/compare?numbers=A,B[,C]` (Figma Compare ads 100:12283). */
export default async function ComparePage({ params, searchParams }: PageProps<"/[market]/classified/compare">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-96 w-full" />}>
        {searchParams.then((raw) => {
          const value = Array.isArray(raw.numbers) ? raw.numbers.join(",") : (raw.numbers ?? "");
          const numbers = value.split(",").map((n) => n.trim()).filter(Boolean).slice(0, 4);
          return <ClassifiedCompare market={market} numbers={numbers} />;
        })}
      </Suspense>
    </Container>
  );
}
