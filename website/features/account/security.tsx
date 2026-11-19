"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Monitor } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert, Badge, Card, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { formatDateTime, humanize } from "@/lib/format";
import { errorMessage, fieldErrors, queryKeys, useErrorMessage, useSession, useSignOut } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

const OUTCOMES = ["succeeded", "wrong_code", "wrong_credentials", "locked", "rejected", "failed"] as const;
const METHODS = ["email_code", "password", "google", "apple"] as const;
const MIN_PASSWORD = 8;

/** `/account/security` — signed-in devices + login history (Figma 52:7780). */
export function SecurityView() {
  const t = useTranslations();
  const market = useMarket();
  const client = useQueryClient();
  const signOut = useSignOut();
  const devices = useQuery({ queryKey: ["devices", market.slug], queryFn: async () => (await api.getDevices(market.slug)).data });
  const history = useQuery({ queryKey: ["login-history", market.slug], queryFn: async () => (await api.getLoginHistory(market.slug)).data });
  const revoke = useMutation({
    mutationFn: async (id: number) => api.revokeDevice(market.slug, id),
    onSuccess: () => client.invalidateQueries({ queryKey: ["devices", market.slug] }),
  });
  const when = (v: string | null) => formatDateTime(v, market.locale, market.country.timeZone);

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("account.security.title")}</h1>
      <PasswordCard />
      <LinkedAccountsCard />
      <Card className="p-0 md:p-0">
        <h2 className="border-b border-line px-5 py-3 text-h4 font-semibold">{t("account.security.devices")}</h2>
        {devices.isLoading ? <Skeleton className="m-5 h-24" /> : null}
        {devices.error ? <Alert tone="danger" className="m-5">{errorMessage(devices.error, t("common.error"))}</Alert> : null}
        <ul className="divide-y divide-line">
          {devices.data?.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center gap-4 px-5 py-3">
              <Monitor className="size-5 text-secondary" />
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-body font-medium">
                  {d.name}
                  {d.is_current ? <Badge tone="success">{t("account.security.thisDevice")}</Badge> : null}
                </p>
                <p className="text-body-sm text-secondary">{d.last_used_at ? t("account.security.lastUsed", { date: when(d.last_used_at) }) : t("account.security.never")}</p>
              </div>
              <button
                type="button"
                onClick={() => (d.is_current ? signOut.mutate() : revoke.mutate(d.id))}
                disabled={revoke.isPending || signOut.isPending}
                className="rounded-md border border-line px-3 py-1.5 text-body-sm font-medium hover:bg-subtle"
              >
                {t("account.security.signOutDevice")}
              </button>
            </li>
          ))}
        </ul>
      </Card>
      <Card className="p-0 md:p-0">
        <h2 className="border-b border-line px-5 py-3 text-h4 font-semibold">{t("account.security.history")}</h2>
        {history.isLoading ? <Skeleton className="m-5 h-24" /> : null}
        <ul className="divide-y divide-line">
          {history.data?.map((e, i) => {
            const outcome = e.outcome as (typeof OUTCOMES)[number];
            const method = e.method as (typeof METHODS)[number] | null | undefined;
            return (
              <li key={i} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-body-sm">
                <span>
                  <span className="font-medium">{OUTCOMES.includes(outcome) ? t(`account.security.outcome.${outcome}`) : humanize(e.outcome ?? "")}</span>
                  <span className="text-secondary">
                    {method ? ` · ${METHODS.includes(method) ? t(`account.security.method.${method}`) : humanize(method)}` : ""}
                    {e.ip_address ? ` · ${e.ip_address}` : ""}
                  </span>
                </span>
                <span className="text-secondary">{when(e.created_at)}</span>
              </li>
            );
          })}
        </ul>
      </Card>
    </div>
  );
}

/** Set (first time) or change the optional password (D9, `POST me/password`). */
function PasswordCard() {
  const t = useTranslations("account.security");
  const market = useMarket();
  const client = useQueryClient();
  const toMessage = useErrorMessage();
  const { user } = useSession();
  const hasPassword = !!user?.has_password;
  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState(false);
  const save = useMutation({
    mutationFn: async () =>
      api.changePassword(market.slug, { ...(hasPassword ? { current_password: current } : {}), password, password_confirmation: confirmation }),
    onSuccess: () => {
      setSaved(true);
      setCurrent("");
      setPassword("");
      setConfirmation("");
      client.invalidateQueries({ queryKey: queryKeys.me });
      client.invalidateQueries({ queryKey: ["devices", market.slug] });
    },
    onError: (e) => {
      setFields(fieldErrors(e));
      setError(toMessage(e));
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setSaved(false);
    setFields({});
    if (password.length < MIN_PASSWORD) return setError(t("passwordTooShort"));
    if (password !== confirmation) return setError(t("passwordMismatch"));
    setError(null);
    save.mutate();
  };

  return (
    <Card>
      <h2 className="text-h4 font-semibold">{t("password")}</h2>
      <p className="mt-1 text-body-sm text-secondary">{hasPassword ? t("passwordChangeHint") : t("passwordSetHint")}</p>
      <form onSubmit={submit} className="mt-4 grid gap-4 md:max-w-md">
        {hasPassword ? (
          <Field label={t("currentPassword")} error={fields.current_password}>
            <Input type="password" required autoComplete="current-password" maxLength={255} value={current} onChange={(e) => setCurrent(e.target.value)} dir="ltr" />
          </Field>
        ) : null}
        <Field label={t("newPassword")} error={fields.password}>
          <Input type="password" required minLength={MIN_PASSWORD} maxLength={255} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} dir="ltr" />
        </Field>
        <Field label={t("confirmPassword")}>
          <Input type="password" required minLength={MIN_PASSWORD} maxLength={255} autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} dir="ltr" />
        </Field>
        {error && !fields.current_password && !fields.password ? <Alert tone="danger">{error}</Alert> : null}
        {saved ? <Alert tone="success">{t("passwordSaved")}</Alert> : null}
        <Button type="submit" disabled={save.isPending} className="justify-self-start">
          {save.isPending ? t("saving") : hasPassword ? t("changePassword") : t("setPassword")}
        </Button>
      </form>
    </Card>
  );
}

/** Linked Google / Apple sign-in (`me.social_accounts`), with unlink. */
function LinkedAccountsCard() {
  const t = useTranslations("account.security");
  const market = useMarket();
  const client = useQueryClient();
  const toMessage = useErrorMessage();
  const { user } = useSession();
  const linked = user?.social_accounts ?? [];
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const unlink = useMutation({
    mutationFn: async (provider: api.SocialProvider) => api.unlinkSocialAccount(market.slug, provider),
    onSuccess: (_, provider) => {
      setNotice({ tone: "success", text: t("unlinked", { provider: t(`provider.${provider}`) }) });
      client.invalidateQueries({ queryKey: queryKeys.me });
    },
    onError: (e) => setNotice({ tone: "danger", text: toMessage(e) }),
  });

  return (
    <Card className="p-0 md:p-0">
      <div className="border-b border-line px-5 py-3">
        <h2 className="text-h4 font-semibold">{t("linked")}</h2>
        <p className="text-body-sm text-secondary">{t("linkedHint")}</p>
      </div>
      {notice ? (
        <Alert tone={notice.tone} className="m-5 mb-0">
          {notice.text}
        </Alert>
      ) : null}
      <ul className="divide-y divide-line">
        {api.SOCIAL_PROVIDERS.map((provider) => {
          const isLinked = linked.includes(provider);
          return (
            <li key={provider} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
              <span className="flex items-center gap-2 text-body font-medium">
                {t(`provider.${provider}`)}
                {isLinked ? <Badge tone="success">{t("linkedBadge")}</Badge> : <span className="text-body-sm font-normal text-secondary">{t("notLinked")}</span>}
              </span>
              {isLinked ? (
                <button
                  type="button"
                  onClick={() => unlink.mutate(provider)}
                  disabled={unlink.isPending}
                  className="rounded-md border border-line px-3 py-1.5 text-body-sm font-medium hover:bg-subtle"
                >
                  {t("unlink")}
                </button>
              ) : null}
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
