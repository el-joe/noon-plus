import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { PortalNav } from "@/features/portal/portal-nav";
import { RegistrationWizard } from "@/features/portal/wizard";
import { isApplicationType } from "@/lib/api/portal";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/join/[type]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const { type } = await params;
  const t = getT(market.locale);
  return { title: isApplicationType(type) ? t(`portal.types.${type}.register`) : t("portal.join.metaTitle"), robots: { index: false } };
}

const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);

/**
 * `/join/{type}` — partner registration wizard (vendor, classified,
 * travel_agency, marketer, broker, carrier). `?number=` opens a saved
 * application, `?role=influencer|affiliate` preselects the marketer type.
 */
async function RegisterPageContent({ params, searchParams }: PageProps<"/[market]/join/[type]">) {
  const market = await resolveMarket(params);
  return (
    <>
      <PortalNav market={market} active="join" />
      <Container className="max-w-4xl py-8">
        <Suspense fallback={<Skeleton className="h-[520px] w-full" />}>
          {Promise.all([params, searchParams]).then(([{ type }, query]) => {
            if (!isApplicationType(type)) notFound();
            return <RegistrationWizard key={`${type}-${one(query.number) ?? ""}`} type={type} number={one(query.number)} role={one(query.role)} />;
          })}
        </Suspense>
      </Container>
    </>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function RegisterPage(props: PageProps<"/[market]/join/[type]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <RegisterPageContent {...props} />
    </Suspense>
  );
}
