import { Search } from "lucide-react";
import { buttonClass } from "@/components/ui/button";
import { inputClass } from "@/components/ui/field";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { TRIP_TYPES } from "./shared";

export type TravelSearchValues = { destination?: string; trip_type?: string; month?: string };

/** Trip search (Figma Travel home 69:5767 hero form) — a plain GET form into /travel/search. */
export function TravelSearchForm({ market, values = {}, className }: { market: Market; values?: TravelSearchValues; className?: string }) {
  const t = getT(market.locale);
  return (
    <form action={`/${market.slug}/travel/search`} method="get" className={`grid gap-3 rounded-lg bg-surface p-3 text-ink sm:grid-cols-2 lg:grid-cols-[1fr_1.2fr_1fr_auto] lg:items-end ${className ?? ""}`}>
      <label className="flex flex-col gap-1">
        <span className="text-caption font-medium text-secondary">{t("travel.search.tripType")}</span>
        <select name="trip_type" defaultValue={values.trip_type ?? ""} className={`${inputClass} pe-8`}>
          <option value="">{t("travel.search.allTypes")}</option>
          {TRIP_TYPES.map((type) => (
            <option key={type} value={type}>
              {t(`travel.types.${type}`)}
            </option>
          ))}
        </select>
      </label>
      <label className="flex flex-col gap-1">
        <span className="text-caption font-medium text-secondary">{t("travel.search.destination")}</span>
        <input name="destination" defaultValue={values.destination ?? ""} maxLength={80} placeholder={t("travel.search.destinationPlaceholder")} className={inputClass} />
      </label>
      <label className="flex flex-col gap-1">
        <span className="text-caption font-medium text-secondary">{t("travel.search.month")}</span>
        <input type="month" name="month" defaultValue={values.month ?? ""} className={inputClass} />
      </label>
      <button type="submit" className={buttonClass("primary", "lg", "w-full lg:w-auto")}>
        <Search className="size-4" />
        {t("travel.search.submit")}
      </button>
    </form>
  );
}
