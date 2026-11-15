"use client";

import clsx from "clsx";
import { Check } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { useMarket } from "@/lib/market-context";
import { COUNTRIES, LOCALES, switchMarketPath, type Locale } from "@/lib/markets";
import { buttonClass } from "../ui/button";
import { Modal } from "./modal";

/** Country & language selector — 8 countries × EN/AR (Figma 107:17386). */
export function CountrySwitcher({ onClose }: { onClose: () => void }) {
  const t = useTranslations("switcher");
  const market = useMarket();
  const [country, setCountry] = useState(market.country.code);
  const [locale, setLocale] = useState<Locale>(market.locale);

  const go = () => {
    // A different country has its own catalogue and cart, so land on its home
    // page; switching only the language keeps the current page.
    const target = `${country}-${locale}`;
    window.location.href = country === market.country.code ? switchMarketPath(window.location.pathname, target) + window.location.search : `/${target}`;
  };

  return (
    <Modal title={t("title")} onClose={onClose}>
      <fieldset className="mb-5">
        <legend className="mb-2 text-label font-medium text-secondary">{t("country")}</legend>
        <div className="grid grid-cols-2 gap-2">
          {COUNTRIES.map((c) => (
            <button
              key={c.code}
              type="button"
              onClick={() => setCountry(c.code)}
              aria-pressed={country === c.code}
              className={clsx(
                "flex items-center justify-between rounded-md border px-3 py-2.5 text-start text-body",
                country === c.code ? "border-brand bg-brand-subtle font-semibold" : "border-line hover:bg-subtle",
              )}
            >
              <span>
                {c.name[market.locale]} <span className="text-caption text-muted">· {c.currency}</span>
              </span>
              {country === c.code ? <Check className="size-4" /> : null}
            </button>
          ))}
        </div>
      </fieldset>
      <fieldset className="mb-5">
        <legend className="mb-2 text-label font-medium text-secondary">{t("language")}</legend>
        <div className="flex gap-2">
          {LOCALES.map((l) => (
            <button
              key={l}
              type="button"
              onClick={() => setLocale(l)}
              aria-pressed={locale === l}
              lang={l}
              className={clsx("flex-1 rounded-md border px-3 py-2.5 text-body", locale === l ? "border-brand bg-brand-subtle font-semibold" : "border-line hover:bg-subtle")}
            >
              {l === "en" ? t("english") : t("arabic")}
            </button>
          ))}
        </div>
      </fieldset>
      <button type="button" onClick={go} className={buttonClass("primary", "lg", "w-full")}>
        {t("go")}
      </button>
    </Modal>
  );
}
