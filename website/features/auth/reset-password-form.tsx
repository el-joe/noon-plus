"use client";

import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { fieldErrors, useErrorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { deviceName, finishSignIn } from "./sign-in-helpers";

const MIN_LENGTH = 8;

/**
 * Password reset (D9): by the emailed link (`token`) or by the 6-digit code.
 * Both sign the customer in (`device_name`); the BFF keeps the token.
 * API errors (reset_token_invalid, code_invalid, code_expired, 429
 * code_locked) are localised by the API and shown as returned.
 */
export function ResetPasswordForm({ email: initialEmail, token }: { email: string; token: string | null }) {
  const t = useTranslations("auth");
  const market = useMarket();
  const client = useQueryClient();
  const toMessage = useErrorMessage();
  const [email, setEmail] = useState(initialEmail);
  const [codeSent, setCodeSent] = useState(false);
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [linkInvalid, setLinkInvalid] = useState(false);
  const byLink = !!token && !linkInvalid;

  const requestCode = async (event?: FormEvent) => {
    event?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.forgotPassword(market.slug, email.trim());
      setCodeSent(true);
    } catch (e) {
      setError(toMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (password.length < MIN_LENGTH) return setError(t("passwordTooShort"));
    if (password !== confirmation) return setError(t("passwordMismatch"));
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.resetPassword(market.slug, {
        email: email.trim(),
        ...(byLink ? { token: token! } : { code: code.trim() }),
        password,
        password_confirmation: confirmation,
        device_name: deviceName(),
      });
      if (data.user) {
        setDone(t("resetDone"));
        await finishSignIn(client, market.slug, data.user, `/${market.slug}/account`);
      } else {
        setDone(t("resetDoneSignIn"));
        setBusy(false);
      }
    } catch (e) {
      const fields = fieldErrors(e);
      setError(fields.password ?? fields.code ?? fields.token ?? toMessage(e));
      if (byLink && e instanceof ApiError && e.code === "reset_token_invalid") setLinkInvalid(true);
      setBusy(false);
    }
  };

  const showPasswordForm = byLink || codeSent;

  return (
    <div className="w-full max-w-md rounded-xl bg-surface p-6 shadow-lg md:p-8">
      <h1 className="text-h2 font-bold">{showPasswordForm ? t("resetTitle") : t("forgotTitle")}</h1>
      <p className="mt-1 text-body text-secondary">{showPasswordForm ? t("resetHint") : t("forgotHint")}</p>

      {done ? (
        <Alert tone="success" className="mt-6">
          {done}
        </Alert>
      ) : null}

      {!done && !showPasswordForm ? (
        <form onSubmit={requestCode} className="mt-6 flex flex-col gap-4">
          {linkInvalid ? <Alert tone="danger">{error}</Alert> : null}
          <Field label={t("email")}>
            <Input type="email" required autoComplete="email" maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t("emailPlaceholder")} dir="ltr" />
          </Field>
          {error && !linkInvalid ? <Alert tone="danger">{error}</Alert> : null}
          <Button type="submit" size="lg" disabled={busy || !email}>
            {busy ? t("sending") : t("sendReset")}
          </Button>
          <button type="button" className="text-body-sm text-link hover:underline" disabled={!email} onClick={() => setCodeSent(true)}>
            {t("haveCode")}
          </button>
        </form>
      ) : null}

      {!done && showPasswordForm ? (
        <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
          {codeSent && !byLink ? <Alert tone="info">{t("resetSent", { email })}</Alert> : null}
          {byLink ? null : (
            <>
              <Field label={t("email")}>
                <Input type="email" required autoComplete="email" maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} dir="ltr" />
              </Field>
              <Field label={t("code")}>
                <Input
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  required
                  pattern="[0-9]{4,8}"
                  maxLength={8}
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
                  className="text-center text-h3 tracking-[0.5em]"
                  dir="ltr"
                />
              </Field>
            </>
          )}
          <Field label={t("newPassword")} hint={t("resetHint")}>
            <Input type="password" required minLength={MIN_LENGTH} maxLength={255} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} dir="ltr" />
          </Field>
          <Field label={t("confirmPassword")}>
            <Input type="password" required minLength={MIN_LENGTH} maxLength={255} autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} dir="ltr" />
          </Field>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Button type="submit" size="lg" disabled={busy || !email || (!byLink && code.length < 4)}>
            {busy ? t("saving") : t("savePassword")}
          </Button>
          {!byLink ? (
            <button type="button" className="text-body-sm text-link hover:underline" onClick={() => requestCode()} disabled={busy}>
              {t("resend")}
            </button>
          ) : null}
        </form>
      ) : null}

      <Link href={`/${market.slug}/login`} className="mt-6 block text-body-sm text-link hover:underline">
        {t("backToSignIn")}
      </Link>
    </div>
  );
}
