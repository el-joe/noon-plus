"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { BadgeCheck, Camera, MapPin, Shield, Trash2 } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
import { Alert, Card, Spinner } from "@/components/ui/misc";
import * as api from "@/lib/api/account";
import type { Me } from "@/lib/api/schemas";
import { formatDay } from "@/lib/format";
import { errorMessage, fieldErrors, queryKeys, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

/**
 * `/account/profile` (Figma Account profile 52:5223): name, email-language,
 * avatar, marketing preferences (`PATCH me`, `POST|DELETE me/avatar`) and an
 * email change confirmed by a code sent to the new address
 * (`POST me/email` → `POST me/email/verify`).
 */
export function ProfileView() {
  const t = useTranslations("accountExtra.profile");
  const market = useMarket();
  const href = useHref();
  const { user } = useSession();
  if (!user) return null;

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("title")}</h1>
      <AvatarCard user={user} />
      <DetailsCard user={user} />
      <EmailCard user={user} />
      <MarketingCard user={user} />
      <div className="flex flex-wrap gap-3">
        <Link href={href("/account/addresses")} className="flex items-center gap-2 rounded-md border border-line bg-surface px-4 py-3 text-body hover:bg-subtle">
          <MapPin className="size-4" />
          {t("addresses")}
        </Link>
        <Link href={href("/account/security")} className="flex items-center gap-2 rounded-md border border-line bg-surface px-4 py-3 text-body hover:bg-subtle">
          <Shield className="size-4" />
          {t("security")}
        </Link>
      </div>
      {user.created_at ? <p className="text-caption text-secondary">{t("memberSince", { date: formatDay(user.created_at, market.locale) })}</p> : null}
    </div>
  );
}

/** Keep the cached session (`GET me`) in step with every profile change. */
function useProfileMutation<V>(fn: (market: string, vars: V) => Promise<{ data: Me }>) {
  const market = useMarket();
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (vars: V) => (await fn(market.slug, vars)).data,
    onSuccess: (me) => client.setQueryData(queryKeys.me, me),
  });
}

function AvatarCard({ user }: { user: Me }) {
  const t = useTranslations("accountExtra.profile");
  const tc = useTranslations("common");
  const upload = useProfileMutation((m, file: File) => api.uploadAvatar(m, file));
  const remove = useProfileMutation((m, _: void) => api.removeAvatar(m));
  const initials = (user.name || user.email).split(/[\s@.]+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("");
  const error = upload.error ?? remove.error;
  const avatar = user.avatar_url && /^(https?:)?\//.test(user.avatar_url) ? user.avatar_url : null;

  return (
    <Card className="flex flex-wrap items-center gap-4">
      <span className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-subtle text-h3 font-semibold text-brand-text">
        {avatar ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={avatar} alt="" className="size-full object-cover" />
        ) : (
          initials
        )}
      </span>
      <div className="flex min-w-0 flex-1 flex-col gap-2">
        <p className="text-body font-semibold">{t("photo")}</p>
        <p className="text-body-sm text-secondary">{t("photoHint")}</p>
        <div className="flex flex-wrap gap-2">
          <label className="inline-flex h-8 cursor-pointer items-center gap-2 rounded-md border border-line-strong bg-surface px-3 text-label font-semibold hover:bg-subtle">
            {upload.isPending ? <Spinner /> : <Camera className="size-4" />}
            {t("uploadPhoto")}
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="sr-only"
              disabled={upload.isPending}
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) upload.mutate(file);
                e.target.value = "";
              }}
            />
          </label>
          {avatar ? (
            <Button size="sm" variant="ghost" onClick={() => remove.mutate()} disabled={remove.isPending}>
              <Trash2 className="size-4" />
              {t("removePhoto")}
            </Button>
          ) : null}
        </div>
        {error ? <p className="text-body-sm text-danger-text">{fieldErrors(error).avatar ?? errorMessage(error, tc("error"))}</p> : null}
      </div>
    </Card>
  );
}

function DetailsCard({ user }: { user: Me }) {
  const t = useTranslations("accountExtra.profile");
  const ts = useTranslations("switcher");
  const tc = useTranslations("common");
  const market = useMarket();
  const [name, setName] = useState(user.name ?? "");
  const [locale, setLocale] = useState<"en" | "ar">(user.locale === "ar" ? "ar" : user.locale === "en" ? "en" : market.locale);
  const save = useProfileMutation((m, body: api.ProfileInput) => api.updateProfile(m, body));
  const errors = fieldErrors(save.error);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    save.mutate({ name: name.trim(), locale });
  };

  return (
    <Card>
      <h2 className="mb-3 text-h4 font-semibold">{t("details")}</h2>
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
        <Field label={t("name")} error={errors.name}>
          <Input value={name} onChange={(e) => setName(e.target.value)} minLength={2} maxLength={120} required autoComplete="name" />
        </Field>
        <Field label={t("preferredLanguage")} error={errors.locale}>
          <Select value={locale} onChange={(e) => setLocale(e.target.value === "ar" ? "ar" : "en")}>
            <option value="en">{ts("english")}</option>
            <option value="ar">{ts("arabic")}</option>
          </Select>
        </Field>
        {user.phone ? (
          <div className="flex flex-col gap-1">
            <span className="text-label font-medium">{t("phone")}</span>
            <span className="text-body" dir="ltr">
              {user.phone}
            </span>
          </div>
        ) : null}
        <div className="flex flex-col gap-1">
          <span className="text-label font-medium">{t("country")}</span>
          <span className="text-body">{market.country.name[market.locale]}</span>
        </div>
        <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
          <Button type="submit" disabled={save.isPending || name.trim().length < 2}>
            {save.isPending ? <Spinner /> : null}
            {t("save")}
          </Button>
          {save.isSuccess ? <span className="text-body-sm text-success-text">{t("saved")}</span> : null}
          {save.error && Object.keys(errors).length === 0 ? <span className="text-body-sm text-danger-text">{errorMessage(save.error, tc("error"))}</span> : null}
        </div>
      </form>
    </Card>
  );
}

function EmailCard({ user }: { user: Me }) {
  const t = useTranslations("accountExtra.profile");
  const tc = useTranslations("common");
  const market = useMarket();
  const [editing, setEditing] = useState(false);
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [changed, setChanged] = useState<string | null>(null);
  const request = useMutation({ mutationFn: async (value: string) => (await api.requestEmailChange(market.slug, value)).data });
  const confirm = useProfileMutation((m, vars: { email: string; code: string }) => api.confirmEmailChange(m, vars.email, vars.code));
  const sentTo = request.data?.email ?? null;
  const error = request.error ?? confirm.error;
  const errors = fieldErrors(error);

  const reset = () => {
    setEditing(false);
    setEmail("");
    setCode("");
    request.reset();
    confirm.reset();
  };
  const send = (e: FormEvent) => {
    e.preventDefault();
    if (email.trim()) request.mutate(email.trim());
  };
  const verify = (e: FormEvent) => {
    e.preventDefault();
    if (!sentTo) return;
    confirm.mutate(
      { email: sentTo, code: code.trim() },
      {
        onSuccess: (me) => {
          setChanged(me.email);
          setEditing(false);
          setCode("");
          request.reset();
        },
      },
    );
  };

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-body-sm text-secondary">{t("email")}</p>
          <p className="flex items-center gap-2 text-body font-medium" dir="ltr">
            {user.email}
            {user.email_verified ? <BadgeCheck className="size-4 text-link" aria-label={t("verified")} /> : null}
          </p>
          <p className="text-body-sm text-secondary">{t("emailHint")}</p>
        </div>
        {!editing ? (
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            {t("changeEmail")}
          </Button>
        ) : null}
      </div>
      {changed ? <Alert tone="success" className="mt-3">{t("emailChanged", { email: changed })}</Alert> : null}
      {editing ? (
        sentTo ? (
          <form onSubmit={verify} className="mt-4 flex flex-col gap-3">
            <Alert tone="info">{t("codeSent", { email: sentTo })}</Alert>
            <Field label={t("code")} error={errors.code}>
              <Input value={code} onChange={(e) => setCode(e.target.value.replace(/\s/g, ""))} inputMode="numeric" autoComplete="one-time-code" maxLength={12} required dir="ltr" />
            </Field>
            {error && !errors.code ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
            <div className="flex gap-2">
              <Button type="submit" disabled={confirm.isPending || !code.trim()}>
                {confirm.isPending ? <Spinner /> : null}
                {t("confirmEmail")}
              </Button>
              <Button variant="ghost" onClick={reset}>
                {t("cancel")}
              </Button>
            </div>
          </form>
        ) : (
          <form onSubmit={send} className="mt-4 flex flex-col gap-3">
            <Field label={t("newEmail")} error={errors.email}>
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} required autoComplete="email" dir="ltr" />
            </Field>
            {error && !errors.email ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
            <div className="flex gap-2">
              <Button type="submit" disabled={request.isPending || !email.trim()}>
                {request.isPending ? <Spinner /> : null}
                {t("sendCode")}
              </Button>
              <Button variant="ghost" onClick={reset}>
                {t("cancel")}
              </Button>
            </div>
          </form>
        )
      ) : null}
    </Card>
  );
}

function MarketingCard({ user }: { user: Me }) {
  const t = useTranslations("accountExtra.profile");
  const tc = useTranslations("common");
  const initial = Object.fromEntries(api.MARKETING_CHANNELS.map((c) => [c, !!user.marketing?.[c]])) as Record<api.MarketingChannel, boolean>;
  const [prefs, setPrefs] = useState(initial);
  const save = useProfileMutation((m, marketing: Record<api.MarketingChannel, boolean>) => api.updateProfile(m, { marketing }));
  useEffect(() => {
    if (user.marketing) setPrefs(Object.fromEntries(api.MARKETING_CHANNELS.map((c) => [c, !!user.marketing?.[c]])) as Record<api.MarketingChannel, boolean>);
  }, [user.marketing]);

  return (
    <Card>
      <h2 className="text-h4 font-semibold">{t("marketingTitle")}</h2>
      <p className="mb-3 text-body-sm text-secondary">{t("marketingHint")}</p>
      <div className="grid gap-2 sm:grid-cols-2">
        {api.MARKETING_CHANNELS.map((channel) => (
          <label key={channel} className="flex cursor-pointer items-center gap-3 rounded-md border border-line p-3 text-body">
            <input
              type="checkbox"
              className="accent-brand"
              checked={prefs[channel]}
              disabled={save.isPending}
              onChange={(e) => {
                const next = { ...prefs, [channel]: e.target.checked };
                setPrefs(next);
                save.mutate(next);
              }}
            />
            {t(`channels.${channel}`)}
          </label>
        ))}
      </div>
      {save.isSuccess ? <p className="mt-2 text-body-sm text-success-text">{t("saved")}</p> : null}
      {save.error ? <p className="mt-2 text-body-sm text-danger-text">{errorMessage(save.error, tc("error"))}</p> : null}
    </Card>
  );
}
