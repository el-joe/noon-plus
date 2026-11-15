"use client";

import clsx from "clsx";
import { useTranslations } from "next-intl";
import { useQuery } from "@tanstack/react-query";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Spinner } from "@/components/ui/misc";
import { getCategoryTree } from "@/lib/api/endpoints";
import type { CategoryNode } from "@/lib/api/schemas";
import { checkStoreSlug } from "@/lib/api/portal";
import { useMarket } from "@/lib/market-context";
import { SOCIAL_NETWORKS, type FieldDef, type Values } from "./step-fields";
import { usePortalLabel } from "./use-portal-t";

/** One data step of a registration wizard, rendered from its field list. */
export function StepForm({
  title,
  fields,
  initial,
  errors,
  pending,
  submitLabel,
  onSubmit,
  onBack,
}: {
  title: string;
  fields: FieldDef[];
  initial: Values;
  errors: Record<string, string>;
  pending: boolean;
  submitLabel: string;
  onSubmit: (values: Values) => void;
  onBack?: () => void;
}) {
  const t = useTranslations("portal");
  const market = useMarket();
  const label = usePortalLabel();
  const [values, setValues] = useState<Values>(initial);
  const [slugState, setSlugState] = useState<"idle" | "checking" | "available" | "taken">("idle");
  const set = (name: string, value: Values[string]) => setValues((v) => ({ ...v, [name]: value }));

  const checkSlug = async (slug: string) => {
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug) || slug.length < 3) return setSlugState("idle");
    setSlugState("checking");
    try {
      const { data } = await checkStoreSlug(market.slug, slug);
      setSlugState(data.available ? "available" : "taken");
    } catch {
      setSlugState("idle");
    }
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    onSubmit(values);
  };

  const fieldError = (name: string) => errors[name] ?? Object.entries(errors).find(([k]) => k.startsWith(`${name}.`))?.[1] ?? null;

  return (
    <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
      <div className="rounded-lg border border-line bg-surface p-5">
        <h2 className="mb-4 text-h3 font-semibold">{title}</h2>
        <div className="grid gap-4 md:grid-cols-2">
          {fields.map((field) => {
            if (field.showIf && !field.showIf(values)) return null;
            const fieldLabel = (
              <>
                {label(`fields.${field.name}`, field.name)}
                {field.required ? " *" : <span className="font-normal text-muted"> ({t("common.optional")})</span>}
              </>
            );
            const error = fieldError(field.name);
            const value = values[field.name];
            const common = { id: `f-${field.name}`, name: field.name, "aria-invalid": error ? true : undefined, dir: field.ltr ? "ltr" : undefined } as const;
            const wide = clsx(field.wide && "md:col-span-2");

            if (field.kind === "checks") {
              const selected = Array.isArray(value) ? value : [];
              return (
                <fieldset key={field.name} className={clsx("flex flex-col gap-2", wide)}>
                  <legend className="mb-1.5 text-label font-medium">{fieldLabel}</legend>
                  <div className="flex flex-wrap gap-2">
                    {field.options?.map((option) => {
                      const on = selected.includes(option);
                      return (
                        <label key={option} className={clsx("flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-body", on ? "border-brand bg-brand-subtle" : "border-line")}>
                          <input type="checkbox" className="accent-brand" checked={on} onChange={() => set(field.name, on ? selected.filter((x) => x !== option) : [...selected, option])} />
                          {label(`options.${field.name}.${option}`, option)}
                        </label>
                      );
                    })}
                  </div>
                  {error ? <span className="text-body-sm text-danger-text">{error}</span> : null}
                </fieldset>
              );
            }

            if (field.kind === "socials") {
              return (
                <fieldset key={field.name} className={clsx("flex flex-col gap-2", wide)}>
                  <legend className="mb-1.5 text-label font-medium">{fieldLabel}</legend>
                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {SOCIAL_NETWORKS.map((network) => (
                      <Field key={network} label={t(`options.socials.${network}`)}>
                        <Input dir="ltr" placeholder="@" value={String(values[`social_${network}`] ?? "")} onChange={(e) => set(`social_${network}`, e.target.value)} />
                      </Field>
                    ))}
                  </div>
                  {error ? <span className="text-body-sm text-danger-text">{error}</span> : null}
                </fieldset>
              );
            }

            let control;
            switch (field.kind) {
              case "select":
                control = (
                  <Select {...common} value={String(value ?? "")} onChange={(e) => set(field.name, e.target.value)} required={field.required}>
                    <option value="">{t("common.choose")}</option>
                    {field.options?.map((option) => (
                      <option key={option} value={option}>
                        {label(`options.${field.name}.${option}`, option)}
                      </option>
                    ))}
                  </Select>
                );
                break;
              case "category":
                control = <CategorySelect {...common} value={String(value ?? "")} onChange={(v) => set(field.name, v)} />;
                break;
              case "textarea":
                control = <Textarea {...common} value={String(value ?? "")} maxLength={field.max} onChange={(e) => set(field.name, e.target.value)} />;
                break;
              case "file":
                control = (
                  <Input
                    {...common}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    className="pt-2"
                    onChange={(e) => set(field.name, e.target.files?.[0] ?? null)}
                  />
                );
                break;
              case "slug":
                control = (
                  <Input
                    {...common}
                    value={String(value ?? "")}
                    onChange={(e) => {
                      set(field.name, e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "-").replace(/-+/g, "-"));
                      setSlugState("idle");
                    }}
                    onBlur={(e) => checkSlug(e.target.value.replace(/^-|-$/g, ""))}
                  />
                );
                break;
              default:
                control = (
                  <Input
                    {...common}
                    type={field.kind === "list" ? "text" : field.kind}
                    inputMode={field.kind === "number" ? "numeric" : undefined}
                    min={field.kind === "number" ? 0 : undefined}
                    maxLength={field.max}
                    autoComplete={field.autoComplete}
                    value={String(value ?? "")}
                    onChange={(e) => set(field.name, e.target.value)}
                    required={field.required}
                  />
                );
            }

            const hint =
              field.kind === "slug" ? (
                slugState === "checking" ? (
                  <Spinner />
                ) : slugState === "available" ? (
                  <span className="text-success-text">{t("wizard.slugAvailable")}</span>
                ) : slugState === "taken" ? (
                  <span className="text-danger-text">{t("wizard.slugTaken")}</span>
                ) : (
                  t("hints.slug")
                )
              ) : field.kind === "list" ? (
                t("hints.list")
              ) : field.name === "password" ? (
                t("hints.password")
              ) : field.name === "phone" || field.name === "whatsapp_phone" ? (
                t("hints.phone")
              ) : field.kind === "file" ? (
                t("hints.logo")
              ) : field.kind === "category" ? (
                t("hints.main_category_id")
              ) : null;

            return (
              <Field key={field.name} label={fieldLabel} error={error} hint={hint} className={wide}>
                {control}
              </Field>
            );
          })}
        </div>
      </div>
      <div className="flex items-center justify-between gap-3">
        {onBack ? (
          <Button variant="ghost" onClick={onBack}>
            {t("common.back")}
          </Button>
        ) : (
          <span />
        )}
        <Button type="submit" size="lg" disabled={pending}>
          {pending ? <Spinner /> : null}
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}

/** Product category picker for the store step (`main_category_id` ← `categories/tree` ids). */
function CategorySelect({ value, onChange, ...rest }: { id: string; name: string; value: string; onChange: (value: string) => void; "aria-invalid"?: boolean; dir?: "ltr" }) {
  const t = useTranslations("portal");
  const market = useMarket();
  const tree = useQuery({ queryKey: ["category-tree", market.slug], staleTime: 10 * 60_000, queryFn: async () => (await getCategoryTree(market.slug)).data });
  const flat: { id: number; name: string; depth: number }[] = [];
  const walk = (nodes: CategoryNode[], depth: number) => {
    for (const node of nodes) {
      if (typeof node.id === "number") flat.push({ id: node.id, name: node.name, depth });
      walk(node.children ?? [], depth + 1);
    }
  };
  walk(tree.data ?? [], 0);
  return (
    <Select {...rest} value={value} onChange={(e) => onChange(e.target.value)} disabled={tree.isLoading}>
      <option value="">{t("common.choose")}</option>
      {flat.map((c) => (
        <option key={c.id} value={c.id}>
          {"\u00a0".repeat(c.depth * 3)}
          {c.name}
        </option>
      ))}
    </Select>
  );
}
