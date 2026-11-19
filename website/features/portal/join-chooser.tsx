"use client";

import clsx from "clsx";
import { Check, Megaphone, Plane, ShoppingBag, Store, Truck } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Badge } from "@/components/ui/misc";
import { isApplicationType, type JoinOption } from "@/lib/api/portal";
import { useMarket } from "@/lib/market-context";
import { COUNTRIES } from "@/lib/markets";
import { captureReferral } from "./referral";

const ICONS = { vendor: ShoppingBag, open_souq: Store, travel_agency: Plane, marketer: Megaphone, shipping: Truck } as const;
const SECTION_KEYS = ["vendor", "open_souq", "travel_agency", "marketer", "shipping"] as const;
type SectionKey = (typeof SECTION_KEYS)[number];
const KNOWN_ROLES = ["influencer", "affiliate", "broker"] as const;
type Role = JoinOption["roles"][number];

/** Wizard path of a role from `join-options` (`/join/{type}`, job type preselected with `?role=`). */
function roleTarget(role: Role): string | null {
  if (!isApplicationType(role.type)) return null;
  return role.job_types.length === 1 ? `/join/${role.type}?role=${encodeURIComponent(role.job_types[0])}` : `/join/${role.type}`;
}

/** Fallback when the API sends no roles (older payloads): the section's first type. */
const SECTION_TYPES: Record<SectionKey, string> = { vendor: "vendor", open_souq: "classified", travel_agency: "travel_agency", marketer: "marketer", shipping: "carrier" };

const isSection = (value: string): value is SectionKey => (SECTION_KEYS as readonly string[]).includes(value);

/** Join chooser (Figma Join chooser v2 101:16699): country, then one way to work with Nawy. */
export function JoinChooser({ options }: { options: JoinOption[] }) {
  const t = useTranslations("portal");
  const market = useMarket();
  const sections = options.filter((o) => isSection(o.section));
  const firstOpen = sections.find((o) => o.enabled)?.section ?? null;
  const [selected, setSelected] = useState<string | null>(firstOpen);
  const [roleKey, setRoleKey] = useState<string | null>(null);
  // FR-FIN-6: remember an account manager's `?ref=` for the wizard the visitor picks next.
  useEffect(() => {
    captureReferral(window.location.search);
  }, []);
  const countryName = market.country.name[market.locale];
  const rolesOf = (option: JoinOption | undefined): Role[] =>
    option?.roles.length ? option.roles : option && isSection(option.section) ? [{ role: option.section, label: option.label, type: option.types[0]?.type ?? SECTION_TYPES[option.section], job_types: [] }] : [];
  const selectedOption = sections.find((o) => o.section === selected);
  const roles = rolesOf(selectedOption);
  const role = roles.find((r) => r.role === roleKey) ?? roles[0] ?? null;
  const target = role ? roleTarget(role) : null;
  const roleLabel = (r: Role, part: "title" | "hint") =>
    (KNOWN_ROLES as readonly string[]).includes(r.role) ? t(`roles.${r.role as (typeof KNOWN_ROLES)[number]}.${part}`) : part === "title" ? r.label : null;

  return (
    <div className="flex flex-col gap-6">
      <section className="rounded-lg border border-line bg-surface p-5">
        <h2 className="mb-3 text-h4 font-semibold">{t("join.whereRegistered")}</h2>
        <div className="flex flex-wrap gap-2">
          {COUNTRIES.map((country) => {
            const active = country.code === market.country.code;
            return (
              <Link
                key={country.code}
                href={`/${country.code}-${market.locale}/join`}
                aria-current={active ? "true" : undefined}
                className={clsx("rounded-full border px-3 py-1.5 text-body-sm", active ? "border-ink bg-ink text-white" : "border-line bg-surface hover:bg-subtle")}
              >
                <span className="me-1.5 font-semibold">{country.iso2}</span>
                {country.name[market.locale]}
              </Link>
            );
          })}
        </div>
      </section>

      <section>
        <h2 className="mb-3 text-h4 font-semibold">{t("join.howToWork")}</h2>
        <div className="grid gap-4 md:grid-cols-2" role="radiogroup" aria-label={t("join.howToWork")}>
          {sections.map((option) => {
            const key = option.section as SectionKey;
            const Icon = ICONS[key];
            const active = selected === option.section;
            return (
              <div
                key={option.section}
                role="radio"
                aria-checked={active}
                aria-disabled={!option.enabled}
                tabIndex={option.enabled ? 0 : -1}
                onClick={() => {
                  if (!option.enabled) return;
                  if (selected !== option.section) setRoleKey(null);
                  setSelected(option.section);
                }}
                onKeyDown={(e) => {
                  if (option.enabled && (e.key === "Enter" || e.key === " ")) {
                    e.preventDefault();
                    setSelected(option.section);
                  }
                }}
                className={clsx(
                  "flex flex-col gap-3 rounded-lg border bg-surface p-5 transition",
                  option.enabled ? "cursor-pointer hover:shadow-sm" : "cursor-not-allowed opacity-60",
                  active ? "border-brand ring-2 ring-brand/40" : "border-line",
                )}
              >
                <div className="flex items-start gap-3">
                  <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-brand-subtle text-brand-text">
                    <Icon className="size-5" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-body-lg font-semibold">{t(`sections.${key}.title`)}</p>
                    <p className="text-body-sm text-secondary">{t(`sections.${key}.body`)}</p>
                  </div>
                  <span className={clsx("flex size-5 shrink-0 items-center justify-center rounded-full border", active ? "border-brand bg-brand" : "border-line-strong")} aria-hidden>
                    {active ? <Check className="size-3" /> : null}
                  </span>
                </div>
                <p className="text-caption text-secondary">{t(`sections.${key}.detail`)}</p>
                {option.enabled && option.roles.length > 1 ? (
                  <div className="grid grid-cols-3 gap-2" onClick={(e) => e.stopPropagation()}>
                    {option.roles.map((r) => (
                      <button
                        key={r.role}
                        type="button"
                        onClick={() => {
                          setSelected(option.section);
                          setRoleKey(r.role);
                        }}
                        className={clsx("rounded-md border p-2 text-start", active && role?.role === r.role ? "border-brand bg-brand-subtle" : "border-line hover:bg-subtle")}
                      >
                        <span className="block text-body-sm font-semibold">{roleLabel(r, "title")}</span>
                        {roleLabel(r, "hint") ? <span className="block text-caption text-secondary">{roleLabel(r, "hint")}</span> : null}
                      </button>
                    ))}
                  </div>
                ) : null}
                <div className="border-t border-line pt-2">
                  {option.enabled ? (
                    <Badge tone="success">{t("join.openIn", { country: countryName })}</Badge>
                  ) : (
                    <Badge>{t("join.closedIn", { country: countryName })}</Badge>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </section>

      <div className="flex flex-wrap items-center justify-between gap-4">
        <p className="text-body-sm text-secondary">
          {selected && isSection(selected)
            ? t("join.selected", { option: t(`sections.${selected}.title`), country: countryName })
            : t("join.noneOpen", { country: countryName })}
        </p>
        <div className="flex items-center gap-4">
          <Link href={`/${market.slug}/join/status`} className="text-body font-medium text-link hover:underline">
            {t("join.checkStatus")}
          </Link>
          {target ? (
            <ButtonLink href={`/${market.slug}${target}`} size="lg">
              {t("common.continue")}
            </ButtonLink>
          ) : null}
        </div>
      </div>
    </div>
  );
}
