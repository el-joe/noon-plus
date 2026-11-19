"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Lock, Mail, Phone } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { useErrorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { deviceName, finishSignIn, safeNext } from "./sign-in-helpers";
import { SocialButtons, useSocialErrorText } from "./social-buttons";

type Step = "email" | "code" | "password" | "forgot" | "forgotSent" | "phone" | "phoneCode";

/**
 * Customer sign-in (D9, Figma Login 28:2 / OTP 28:107): the email code stays
 * first; password, "Forgot password?" and Google / Apple are alternatives.
 * The BFF proxy stores the Sanctum token in an httpOnly cookie; afterwards
 * the guest cart is merged into the customer's cart. An old Nawy account
 * that only had a phone number signs in once with an SMS code and is then
 * sent to add and verify an email (`/auth/add-email`).
 */
export function SignInForm() {
  const t = useTranslations("auth");
  const market = useMarket();
  const params = useSearchParams();
  const client = useQueryClient();
  const toMessage = useErrorMessage();
  const socialErrorText = useSocialErrorText();
  const [step, setStep] = useState<Step>("email");
  const [email, setEmail] = useState(params.get("email") ?? "");
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const socialError = params.get("social_error");
  const next = safeNext(params.get("next"), market.slug);

  const go = (target: Step) => {
    setStep(target);
    setError(null);
    setCode("");
    setPassword("");
  };

  const run = async (work: () => Promise<void>, keepBusy = false) => {
    setBusy(true);
    setError(null);
    try {
      await work();
      if (!keepBusy) setBusy(false);
    } catch (e) {
      setError(e instanceof ApiError && e.code === "account_suspended" ? socialErrorText("account_suspended", e.message) : toMessage(e));
      setBusy(false);
    }
  };

  const send = (event?: FormEvent) => {
    event?.preventDefault();
    void run(async () => {
      await api.sendEmailCode(market.slug, email.trim());
      setStep("code");
    });
  };

  const verify = (event: FormEvent) => {
    event.preventDefault();
    void run(async () => {
      const { data } = await api.verifyEmailCode(market.slug, email.trim(), code.trim(), deviceName());
      await finishSignIn(client, market.slug, data.user, next);
    }, true);
  };

  const sendPhone = (event?: FormEvent) => {
    event?.preventDefault();
    void run(async () => {
      await api.sendPhoneCode(market.slug, phone.trim());
      setStep("phoneCode");
    });
  };

  const verifyPhone = (event: FormEvent) => {
    event.preventDefault();
    void run(async () => {
      const { data } = await api.verifyPhoneCode(market.slug, phone.trim(), code.trim(), deviceName());
      const target = data.user.requires_email ? `/${market.slug}/auth/add-email?next=${encodeURIComponent(next)}` : next;
      await finishSignIn(client, market.slug, data.user, target);
    }, true);
  };

  const signInWithPassword = (event: FormEvent) => {
    event.preventDefault();
    void run(async () => {
      const { data } = await api.passwordLogin(market.slug, email.trim(), password, deviceName());
      await finishSignIn(client, market.slug, data.user, next);
    }, true);
  };

  const forgot = (event: FormEvent) => {
    event.preventDefault();
    void run(async () => {
      await api.forgotPassword(market.slug, email.trim());
      setStep("forgotSent");
    });
  };

  const title = { email: t("welcome"), code: t("codeTitle"), password: t("welcome"), forgot: t("forgotTitle"), forgotSent: t("forgotTitle"), phone: t("phoneTitle"), phoneCode: t("codeTitle") }[step];
  const subtitle = { email: t("subtitle"), code: t("codeSent", { email }), password: t("subtitle"), forgot: t("forgotHint"), forgotSent: null, phone: t("phoneHint"), phoneCode: t("phoneCodeSent", { phone }) }[step];

  const emailField = (
    <Field label={t("email")}>
      <div className="relative">
        <Mail aria-hidden className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <Input type="email" required autoComplete="email" maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t("emailPlaceholder")} className="ps-9" dir="ltr" />
      </div>
    </Field>
  );
  const errorAlert = error ? <Alert tone="danger">{error}</Alert> : null;

  return (
    <div className="w-full max-w-md rounded-xl bg-surface p-6 shadow-lg md:p-8">
      <h1 className="text-h2 font-bold">{title}</h1>
      {subtitle ? <p className="mt-1 text-body text-secondary">{subtitle}</p> : null}

      {socialError && (step === "email" || step === "password") ? (
        <Alert tone="danger" className="mt-4">
          {socialErrorText(socialError)}
        </Alert>
      ) : null}

      {step === "email" ? (
        <form onSubmit={send} className="mt-6 flex flex-col gap-4">
          {emailField}
          {errorAlert}
          <Button type="submit" size="lg" disabled={busy || !email}>
            {busy ? t("sending") : t("sendCode")}
          </Button>
          <button type="button" className="text-body-sm text-link hover:underline" onClick={() => go("password")}>
            {t("usePassword")}
          </button>
          <button type="button" className="text-body-sm text-link hover:underline" onClick={() => go("phone")}>
            {t("usePhone")}
          </button>
        </form>
      ) : null}

      {step === "phone" ? (
        <form onSubmit={sendPhone} className="mt-6 flex flex-col gap-4">
          <Field label={t("phone")}>
            <div className="relative">
              <Phone aria-hidden className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
              <Input type="tel" required autoComplete="tel" maxLength={32} value={phone} onChange={(e) => setPhone(e.target.value)} placeholder={t("phonePlaceholder")} className="ps-9" dir="ltr" autoFocus />
            </div>
          </Field>
          {errorAlert}
          <Button type="submit" size="lg" disabled={busy || phone.trim().length < 6}>
            {busy ? t("sending") : t("sendSmsCode")}
          </Button>
          <button type="button" className="text-body-sm text-link hover:underline" onClick={() => go("email")}>
            {t("useCode")}
          </button>
        </form>
      ) : null}

      {step === "phoneCode" ? (
        <form onSubmit={verifyPhone} className="mt-6 flex flex-col gap-4">
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
              autoFocus
            />
          </Field>
          {errorAlert}
          <Button type="submit" size="lg" disabled={busy || code.length < 4}>
            {busy ? t("verifying") : t("verify")}
          </Button>
          <div className="flex justify-between text-body-sm">
            <button type="button" className="text-link" onClick={() => sendPhone()} disabled={busy}>
              {t("resend")}
            </button>
            <button type="button" className="text-link" onClick={() => go("phone")}>
              {t("changePhone")}
            </button>
          </div>
        </form>
      ) : null}

      {step === "password" ? (
        <form onSubmit={signInWithPassword} className="mt-6 flex flex-col gap-4">
          {emailField}
          <Field label={t("password")}>
            <div className="relative">
              <Lock aria-hidden className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
              <Input type="password" required autoComplete="current-password" maxLength={255} value={password} onChange={(e) => setPassword(e.target.value)} className="ps-9" dir="ltr" autoFocus />
            </div>
          </Field>
          {errorAlert}
          <Button type="submit" size="lg" disabled={busy || !email || !password}>
            {busy ? t("signingIn") : t("passwordSignIn")}
          </Button>
          <div className="flex flex-wrap justify-between gap-2 text-body-sm">
            <button type="button" className="text-link hover:underline" onClick={() => go("forgot")}>
              {t("forgotPassword")}
            </button>
            <button type="button" className="text-link hover:underline" onClick={() => go("email")}>
              {t("useCode")}
            </button>
          </div>
        </form>
      ) : null}

      {step === "code" ? (
        <form onSubmit={verify} className="mt-6 flex flex-col gap-4">
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
              autoFocus
            />
          </Field>
          {errorAlert}
          <Button type="submit" size="lg" disabled={busy || code.length < 4}>
            {busy ? t("verifying") : t("verify")}
          </Button>
          <div className="flex justify-between text-body-sm">
            <button type="button" className="text-link" onClick={() => send()} disabled={busy}>
              {t("resend")}
            </button>
            <button type="button" className="text-link" onClick={() => go("email")}>
              {t("changeEmail")}
            </button>
          </div>
        </form>
      ) : null}

      {step === "forgot" ? (
        <form onSubmit={forgot} className="mt-6 flex flex-col gap-4">
          {emailField}
          {errorAlert}
          <Button type="submit" size="lg" disabled={busy || !email}>
            {busy ? t("sending") : t("sendReset")}
          </Button>
          <button type="button" className="text-body-sm text-link hover:underline" onClick={() => go("password")}>
            {t("backToSignIn")}
          </button>
        </form>
      ) : null}

      {step === "forgotSent" ? (
        <div className="mt-6 flex flex-col gap-4">
          <Alert tone="success">{t("resetSent", { email })}</Alert>
          <Link href={`/${market.slug}/reset-password?email=${encodeURIComponent(email.trim())}`} className="text-body-sm text-link hover:underline">
            {t("haveCode")}
          </Link>
          <button type="button" className="text-start text-body-sm text-link hover:underline" onClick={() => go("password")}>
            {t("backToSignIn")}
          </button>
        </div>
      ) : null}

      {step === "email" || step === "password" ? (
        <>
          <div className="my-5 flex items-center gap-3 text-caption text-muted" aria-hidden>
            <span className="h-px flex-1 bg-line" />
            {t("or")}
            <span className="h-px flex-1 bg-line" />
          </div>
          <SocialButtons returnTo={next} onError={setError} />
        </>
      ) : null}
      <p className="mt-6 text-caption text-muted">{t("terms")}</p>
    </div>
  );
}
