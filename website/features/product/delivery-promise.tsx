"use client";

import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import type { DeliveryEstimate } from "@/lib/api/schemas";
import { formatDay } from "@/lib/format";
import { useMarket } from "@/lib/market-context";

/** "Get it by …" + cut-off countdown ("Order within 4h 12m") from DeliveryEstimate. */
export function DeliveryPromise({ delivery, express }: { delivery: DeliveryEstimate | null; express?: boolean }) {
  const t = useTranslations("product");
  const market = useMarket();
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    setNow(Date.now());
    const timer = window.setInterval(() => setNow(Date.now()), 60_000);
    return () => window.clearInterval(timer);
  }, []);
  if (!delivery) return null;
  const same = delivery.from === delivery.by;
  const cutoff = delivery.order_before ? new Date(delivery.order_before).getTime() - (now ?? 0) : 0;
  const hours = Math.floor(cutoff / 3_600_000);
  const minutes = Math.floor((cutoff % 3_600_000) / 60_000);
  return (
    <div className="flex flex-col gap-1">
      <p className="flex flex-wrap items-center gap-1.5 text-body-sm">
        {express ? <span className="rounded-xs bg-brand px-1.5 text-caption font-extrabold italic">{t("express")}</span> : null}
        <span className="font-medium">
          {same
            ? t("getItBy", { date: formatDay(delivery.by, market.locale, true) })
            : t("getItBetween", { from: formatDay(delivery.from, market.locale, true), to: formatDay(delivery.by, market.locale, true) })}
        </span>
      </p>
      {now !== null && cutoff > 0 ? <p className="text-body-sm text-success-text">{t("orderWithin", { time: `${hours}h ${minutes}m` })}</p> : null}
    </div>
  );
}
