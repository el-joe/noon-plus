import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { PortalNav } from "@/features/portal/portal-nav";
import { ResumeView } from "@/features/portal/resume-view";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/join/resume">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("portal.resume.title"), robots: { index: false }, referrer: "no-referrer" };
}

const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** `/join/resume?number=&token=` — resume link from the application emails (P3). */
export default async function ResumePage({ params, searchParams }: PageProps<"/[market]/join/resume">) {
  const market = await resolveMarket(params);
  return (
    <>
      <PortalNav market={market} active="join" />
      <Container className="max-w-3xl py-8">
        <Suspense fallback={<Skeleton className="h-64 w-full" />}>
          {searchParams.then((query) => (
            <ResumeView number={one(query.number)} token={one(query.token)} />
          ))}
        </Suspense>
      </Container>
    </>
  );
}
