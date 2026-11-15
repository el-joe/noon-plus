"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert, Skeleton, Spinner } from "@/components/ui/misc";
import { getApplicationAgreement, type Application } from "@/lib/api/portal";
import { useMarket } from "@/lib/market-context";

/** Agreement & e-signature (Figma Seller reg — agreement 73:724): click-wrap with the typed full name, then submit. */
export function AgreementStep({
  app,
  onSubmit,
  onBack,
  pending,
  errors,
  error,
}: {
  app: Application;
  onSubmit: (fullName: string) => void;
  onBack: () => void;
  pending: boolean;
  errors: Record<string, string>;
  error: string | null;
}) {
  const t = useTranslations("portal");
  const market = useMarket();
  const [accepted, setAccepted] = useState(false);
  const [name, setName] = useState(app.account.name ?? "");
  const [localError, setLocalError] = useState<string | null>(null);
  const agreement = useQuery({ queryKey: ["partner-agreement", market.slug, app.number], queryFn: async () => (await getApplicationAgreement(market.slug, app.number)).data });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!accepted) return setLocalError(t("agreement.mustAccept"));
    setLocalError(null);
    onSubmit(name.trim());
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-5">
      <div className="rounded-lg border border-line bg-surface p-5">
        <h2 className="mb-1 text-h3 font-semibold">{t("steps.agreement")}</h2>
        {agreement.isLoading ? (
          <Skeleton className="h-48 w-full" />
        ) : agreement.data?.required ? (
          <>
            <p className="mb-3 text-body-sm text-secondary">
              {agreement.data.title} · {t("agreement.version", { version: String(agreement.data.version) })}
            </p>
            {agreement.data.text ? (
              <div className="max-h-80 overflow-y-auto whitespace-pre-wrap rounded-md border border-line bg-subtle p-4 text-body-sm" tabIndex={0}>
                {agreement.data.text}
              </div>
            ) : (
              <p className="text-body-sm text-secondary">{t("agreement.fileOnly")}</p>
            )}
          </>
        ) : (
          <p className="text-body-sm text-secondary">{t("agreement.noneRequired")}</p>
        )}
        <label className="mt-4 flex items-start gap-2 text-body">
          <input type="checkbox" className="mt-1 accent-brand" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
          <span>{agreement.data?.required ? t("agreement.accept") : t("agreement.confirm")}</span>
        </label>
        {localError || errors.accept ? <p className="mt-1 text-body-sm text-danger-text">{localError ?? errors.accept}</p> : null}
        <Field label={`${t("agreement.fullName")} *`} hint={t("agreement.fullNameHint")} error={errors.full_name} className="mt-4 max-w-md">
          <Input value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" required minLength={2} maxLength={160} />
        </Field>
      </div>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <div className="flex items-center justify-between gap-3">
        <Button variant="ghost" onClick={onBack}>
          {t("common.back")}
        </Button>
        <Button type="submit" size="lg" disabled={pending || name.trim().length < 2}>
          {pending ? <Spinner /> : null}
          {t("agreement.submit")}
        </Button>
      </div>
    </form>
  );
}
