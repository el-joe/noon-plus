import type { Metadata } from "next";
import { BadgeCheck, Building, Building2, Languages, MapPin, ShieldCheck, Users } from "lucide-react";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Card, Container, EmptyState, Skeleton } from "@/components/ui/misc";
import { FollowButton } from "@/features/classifieds/follow-button";
import { PropertyCardView } from "@/features/real-estate/shared";
import type { MarketParams } from "@/features/travel/page-types";
import { orNull } from "@/lib/api/client";
import { getBroker, type BrokerProfile } from "@/lib/api/real-estate";
import { formatDay } from "@/lib/format";
import { getT } from "@/lib/i18n";
import { parseMarket, type Market } from "@/lib/markets";

type Props = { params: MarketParams<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { market: slug, slug: broker } = await params;
  const market = parseMarket(slug);
  if (!market) return {};
  const profile = await orNull(getBroker(market.slug, broker).then((r) => r.data)).catch(() => null);
  return { title: profile?.name ?? getT(market.locale)("realEstate.title"), description: profile?.bio?.slice(0, 160) ?? undefined };
}

/** `/brokers/[slug]` — broker profile (Figma 59:4007): licence, office, areas, live properties. */
export default function BrokerPage({ params }: Props) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[600px] w-full" />}>
        <BrokerContent params={params} />
      </Suspense>
    </Container>
  );
}

async function BrokerContent({ params }: Props) {
  const { market: slug, slug: broker } = await params;
  const market = parseMarket(slug);
  if (!market) notFound();
  const profile = await orNull(getBroker(market.slug, broker).then((r) => r.data));
  if (!profile) notFound();
  return <BrokerView market={market} broker={profile} />;
}

function BrokerView({ market, broker }: { market: Market; broker: BrokerProfile }) {
  const t = getT(market.locale);
  const initials = (broker.name ?? broker.slug).split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("");
  return (
    <div className="flex flex-col gap-5">
      <Card className="flex flex-col gap-4 md:flex-row md:items-start">
        <span className="flex size-20 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-h2 font-bold text-brand-text">{initials}</span>
        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <h1 className="flex flex-wrap items-center gap-2 text-h2 font-bold">
            {broker.name}
            {broker.verified ? (
              <span className="inline-flex items-center gap-1 rounded-full bg-info-subtle px-2 py-0.5 text-caption font-medium text-link">
                <BadgeCheck className="size-3.5" />
                {t("realEstate.broker.verified")}
              </span>
            ) : null}
          </h1>
          <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-body-sm text-secondary">
            {broker.office ? (
              <span className="inline-flex items-center gap-1">
                <Building className="size-4" />
                {broker.office}
              </span>
            ) : null}
            <span className="inline-flex items-center gap-1">
              <Users className="size-4" />
              {t("realEstate.broker.followers", { count: broker.followers })}
            </span>
          </p>
          {broker.bio ? <p className="whitespace-pre-line text-body text-secondary">{broker.bio}</p> : null}
          <FollowButton slug={broker.slug} type="marketer" />
        </div>
        {broker.licence?.number ? (
          <div className="flex flex-col gap-1 rounded-md border border-line p-3 text-body-sm md:w-64">
            <p className="flex items-center gap-1.5 font-semibold">
              <ShieldCheck className="size-4 text-success" />
              {t("realEstate.broker.licence", { number: broker.licence.number })}
            </p>
            {broker.licence.authority ? <p className="text-secondary">{t("realEstate.broker.authority", { authority: broker.licence.authority })}</p> : null}
            {broker.licence.expires_on ? <p className="text-secondary">{t("realEstate.broker.expires", { date: formatDay(broker.licence.expires_on, market.locale) })}</p> : null}
          </div>
        ) : null}
      </Card>

      {(broker.areas?.length ?? 0) > 0 || (broker.languages?.length ?? 0) > 0 ? (
        <div className="grid gap-3 md:grid-cols-2">
          {broker.areas?.length ? (
            <Card>
              <h2 className="mb-2 flex items-center gap-2 text-h4 font-semibold">
                <MapPin className="size-4" />
                {t("realEstate.broker.areas")}
              </h2>
              <div className="flex flex-wrap gap-2">
                {broker.areas.map((a) => (
                  <span key={a} className="rounded-full border border-line px-3 py-1 text-body-sm">
                    {a}
                  </span>
                ))}
              </div>
            </Card>
          ) : null}
          {broker.languages?.length ? (
            <Card>
              <h2 className="mb-2 flex items-center gap-2 text-h4 font-semibold">
                <Languages className="size-4" />
                {t("realEstate.broker.languages")}
              </h2>
              <p className="text-body">{broker.languages.join(" · ")}</p>
            </Card>
          ) : null}
        </div>
      ) : null}

      <section>
        <h2 className="mb-3 text-h3 font-semibold">{t("realEstate.broker.properties")}</h2>
        {broker.properties.length === 0 ? (
          <EmptyState icon={<Building2 className="size-10" />} title={t("realEstate.broker.noProperties")} />
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {broker.properties.map((ad) => (
              <PropertyCardView key={ad.number} ad={ad} market={market} />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
