"use client";

import clsx from "clsx";
import { X } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { useHref } from "@/lib/market-context";
import { readBrowserCookie } from "@/lib/session";

/**
 * Cookie consent (Figma 107:17754). Non-essential categories default to OFF:
 * nothing optional runs until the visitor opts in. The choice lives in the
 * first-party cookie `nawy_consent` ("v1:a0p0d0" — analytics, personalisation,
 * advertising) for 6 months; other code reads it with `readConsent()` and can
 * listen for the `nawy:consent` event. The footer "Cookie settings" link
 * reopens the dialog (`nawy:open-consent`).
 */
export const CONSENT_COOKIE = "nawy_consent";
const MAX_AGE = 60 * 60 * 24 * 182;

export type Consent = { analytics: boolean; personalisation: boolean; advertising: boolean };
const NONE: Consent = { analytics: false, personalisation: false, advertising: false };

export function readConsent(): Consent | null {
  const raw = readBrowserCookie(CONSENT_COOKIE);
  const match = raw ? /^v1:a([01])p([01])d([01])$/.exec(raw) : null;
  if (!match) return null;
  return { analytics: match[1] === "1", personalisation: match[2] === "1", advertising: match[3] === "1" };
}

function saveConsent(consent: Consent) {
  const value = `v1:a${+consent.analytics}p${+consent.personalisation}d${+consent.advertising}`;
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${CONSENT_COOKIE}=${value}; Path=/; Max-Age=${MAX_AGE}; SameSite=Lax${secure}`;
  window.dispatchEvent(new CustomEvent("nawy:consent", { detail: consent }));
}

export function openCookieSettings() {
  window.dispatchEvent(new Event("nawy:open-consent"));
}

export function CookieConsent() {
  const t = useTranslations("content.cookies");
  const tc = useTranslations("common");
  const href = useHref();
  const [bannerOpen, setBannerOpen] = useState(false);
  const [prefsOpen, setPrefsOpen] = useState(false);
  const [choice, setChoice] = useState<Consent>(NONE);

  useEffect(() => {
    const existing = readConsent();
    if (!existing) setBannerOpen(true);
    const reopen = () => {
      setChoice(readConsent() ?? NONE);
      setPrefsOpen(true);
    };
    window.addEventListener("nawy:open-consent", reopen);
    return () => window.removeEventListener("nawy:open-consent", reopen);
  }, []);

  const decide = (consent: Consent) => {
    saveConsent(consent);
    setBannerOpen(false);
    setPrefsOpen(false);
  };

  const categories = [
    { key: "analytics", label: t("analytics"), hint: t("analyticsHint") },
    { key: "personalisation", label: t("personalisation"), hint: t("personalisationHint") },
    { key: "advertising", label: t("advertising"), hint: t("advertisingHint") },
  ] as const;

  return (
    <>
      {bannerOpen && !prefsOpen ? (
        <section role="region" aria-label={t("title")} className="fixed inset-x-0 bottom-0 z-50 border-t border-line bg-surface shadow-lg">
          <div className="mx-auto flex max-w-(--container-page) flex-col gap-3 px-4 py-4 md:flex-row md:items-center md:px-8">
            <div className="flex-1">
              <p className="text-body font-semibold">{t("title")}</p>
              <p className="text-body-sm text-secondary">
                {t("body")}{" "}
                <Link href={href("/pages/cookie-policy")} className="text-link underline">
                  {t("policy")}
                </Link>
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <button type="button" onClick={() => setPrefsOpen(true)} className="h-10 px-3 text-body font-semibold hover:underline">
                {t("manage")}
              </button>
              <button type="button" onClick={() => decide(NONE)} className="h-10 rounded-md border border-line-strong px-4 text-body font-semibold">
                {t("reject")}
              </button>
              <button type="button" onClick={() => decide({ analytics: true, personalisation: true, advertising: true })} className="h-10 rounded-md border border-line-strong px-4 text-body font-semibold">
                {t("accept")}
              </button>
            </div>
          </div>
        </section>
      ) : null}

      {prefsOpen ? (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-4 md:items-center" onClick={() => setPrefsOpen(false)}>
          <div role="dialog" aria-modal="true" aria-labelledby="cookie-prefs-title" className="w-full max-w-md rounded-lg bg-surface p-5 shadow-lg" onClick={(e) => e.stopPropagation()}>
            <div className="mb-4 flex items-center justify-between">
              <h2 id="cookie-prefs-title" className="text-h3 font-semibold">
                {t("prefsTitle")}
              </h2>
              <button type="button" onClick={() => setPrefsOpen(false)} aria-label={tc("close")} className="rounded-md p-1 hover:bg-subtle">
                <X className="size-5" />
              </button>
            </div>
            <ul className="flex flex-col gap-4">
              <li className="flex items-start justify-between gap-4">
                <span>
                  <span className="flex items-center gap-2 text-body font-medium">
                    {t("necessary")}
                    <span className="rounded-full bg-subtle px-2 py-0.5 text-caption text-secondary">{t("alwaysOn")}</span>
                  </span>
                  <span className="text-body-sm text-secondary">{t("necessaryHint")}</span>
                </span>
                <Toggle checked disabled label={t("necessary")} onChange={() => undefined} />
              </li>
              {categories.map(({ key, label, hint }) => (
                <li key={key} className="flex items-start justify-between gap-4">
                  <span>
                    <span className="block text-body font-medium">{label}</span>
                    <span className="text-body-sm text-secondary">{hint}</span>
                  </span>
                  <Toggle checked={choice[key]} label={label} onChange={(v) => setChoice((c) => ({ ...c, [key]: v }))} />
                </li>
              ))}
            </ul>
            <p className="mt-4 text-caption text-muted">{t("note")}</p>
            <div className="mt-4 grid grid-cols-2 gap-2">
              <button type="button" onClick={() => decide(NONE)} className="h-10 rounded-md border border-line-strong text-body font-semibold">
                {t("rejectAll")}
              </button>
              <button type="button" onClick={() => decide(choice)} className="h-10 rounded-md bg-brand text-body font-semibold">
                {t("save")}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}

function Toggle({ checked, disabled, label, onChange }: { checked: boolean; disabled?: boolean; label: string; onChange: (value: boolean) => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={clsx("relative h-6 w-11 shrink-0 rounded-full transition disabled:opacity-70", checked ? "bg-brand" : "bg-line-strong")}
    >
      <span className={clsx("absolute top-0.5 size-5 rounded-full bg-white shadow transition-all", checked ? "start-[22px]" : "start-0.5")} />
    </button>
  );
}

/** Footer link that reopens the preferences. */
export function CookieSettingsLink({ label }: { label: string }) {
  return (
    <button type="button" onClick={openCookieSettings} className="text-start text-body-sm text-secondary hover:text-ink">
      {label}
    </button>
  );
}
