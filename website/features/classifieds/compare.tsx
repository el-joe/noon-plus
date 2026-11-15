import Link from "next/link";
import { Alert, EmptyState } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import { ApiError } from "@/lib/api/client";
import { formatMoney } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { AdImage } from "./ad-card";

/** `/classified/compare?numbers=A,B` — compare live ads of one category (Figma 100:12283). */
export async function ClassifiedCompare({ market, numbers }: { market: Market; numbers: string[] }) {
  const t = getT(market.locale);
  if (numbers.length < 2) return <EmptyState title={t("classifieds.compare.title")} body={t("classifieds.compare.pickTwo")} />;
  let error: string | null = null;
  const data = await api
    .compareAds(market.slug, numbers)
    .then((r) => r.data)
    .catch((e: unknown) => {
      error = e instanceof ApiError && e.status === 422 ? t("classifieds.compare.mismatch") : t("common.error");
      return null;
    });
  if (!data) return <Alert tone="danger">{error ?? t("common.error")}</Alert>;

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("classifieds.compare.title")}</h1>
      <div className="overflow-x-auto rounded-lg border border-line bg-surface">
        <table className="w-full min-w-[640px] text-body-sm">
          <thead>
            <tr>
              <th className="w-40 p-3" />
              {data.ads.map((ad) => (
                <th key={ad.number} className="p-3 text-start align-top font-normal">
                  <Link href={`/${market.slug}/classified/find/${encodeURIComponent(ad.number)}`} className="flex flex-col gap-2">
                    <span className="block aspect-[4/3] w-full overflow-hidden rounded-md bg-subtle">
                      <AdImage src={ad.image} alt={ad.title} />
                    </span>
                    <span className="line-clamp-2 font-semibold">{ad.title}</span>
                  </Link>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            <tr className="bg-subtle">
              <th className="p-3 text-start font-normal text-secondary">{t("classifieds.compare.price")}</th>
              {data.ads.map((ad) => (
                <td key={ad.number} className="p-3 font-semibold">
                  {ad.price ? formatMoney(ad.price) : "—"}
                </td>
              ))}
            </tr>
            <tr>
              <th className="p-3 text-start font-normal text-secondary">{t("classifieds.city")}</th>
              {data.ads.map((ad) => (
                <td key={ad.number} className="p-3">
                  {ad.city ?? "—"}
                </td>
              ))}
            </tr>
            {data.attributes.map((a, i) => (
              <tr key={a.key} className={i % 2 === 0 ? "bg-subtle" : ""}>
                <th className="p-3 text-start font-normal text-secondary">
                  {a.label ?? a.key}
                  {a.unit ? ` (${a.unit})` : ""}
                </th>
                {data.ads.map((ad) => (
                  <td key={ad.number} className="p-3">
                    {api.specText(ad.specs?.[a.key]) || "—"}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
