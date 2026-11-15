"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Mail } from "lucide-react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { errorMessage, queryKeys } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

function deviceName(): string {
  const ua = navigator.userAgent;
  const browser = /Edg\//.test(ua) ? "Edge" : /Chrome\//.test(ua) ? "Chrome" : /Firefox\//.test(ua) ? "Firefox" : /Safari\//.test(ua) ? "Safari" : "Browser";
  const os = /Windows/.test(ua) ? "Windows" : /Mac OS/.test(ua) ? "macOS" : /Android/.test(ua) ? "Android" : /iPhone|iPad/.test(ua) ? "iOS" : /Linux/.test(ua) ? "Linux" : "";
  return `Web · ${browser}${os ? ` on ${os}` : ""}`.slice(0, 100);
}

/** Only same-market, root-relative return paths. */
function safeNext(next: string | null, market: string): string {
  return next && next.startsWith(`/${market}`) && !next.startsWith("//") ? next : `/${market}/account`;
}

/**
 * Email sign-in by one-time code (ADR 5, Figma Login 28:2 / OTP 28:107).
 * The BFF proxy stores the Sanctum token in an httpOnly cookie; afterwards
 * the guest cart is merged into the customer's cart.
 */
export function SignInForm() {
  const t = useTranslations("auth");
  const tc = useTranslations("common");
  const market = useMarket();
  const params = useSearchParams();
  const client = useQueryClient();
  const [step, setStep] = useState<"email" | "code">("email");
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const send = async (event?: FormEvent) => {
    event?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.sendEmailCode(market.slug, email.trim());
      setStep("code");
    } catch (e) {
      setError(errorMessage(e, tc("error")));
    } finally {
      setBusy(false);
    }
  };

  const verify = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.verifyEmailCode(market.slug, email.trim(), code.trim(), deviceName());
      client.setQueryData(queryKeys.me, data.user);
      await api.mergeCart(market.slug).catch(() => undefined);
      await client.invalidateQueries();
      window.location.href = safeNext(params.get("next"), market.slug);
    } catch (e) {
      setError(errorMessage(e, tc("error")));
      setBusy(false);
    }
  };

  return (
    <div className="w-full max-w-md rounded-xl bg-surface p-6 shadow-lg md:p-8">
      <h1 className="text-h2 font-bold">{step === "email" ? t("welcome") : t("codeTitle")}</h1>
      <p className="mt-1 text-body text-secondary">{step === "email" ? t("subtitle") : t("codeSent", { email })}</p>

      {step === "email" ? (
        <form onSubmit={send} className="mt-6 flex flex-col gap-4">
          <Field label={t("email")}>
            <div className="relative">
              <Mail className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
              <Input type="email" required autoComplete="email" maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t("emailPlaceholder")} className="ps-9" dir="ltr" />
            </div>
          </Field>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Button type="submit" size="lg" disabled={busy || !email}>
            {busy ? t("sending") : t("sendCode")}
          </Button>
        </form>
      ) : (
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
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Button type="submit" size="lg" disabled={busy || code.length < 4}>
            {busy ? t("verifying") : t("verify")}
          </Button>
          <div className="flex justify-between text-body-sm">
            <button type="button" className="text-link" onClick={() => send()} disabled={busy}>
              {t("resend")}
            </button>
            <button
              type="button"
              className="text-link"
              onClick={() => {
                setStep("email");
                setCode("");
                setError(null);
              }}
            >
              {t("changeEmail")}
            </button>
          </div>
        </form>
      )}
      <p className="mt-6 text-caption text-muted">{t("terms")}</p>
    </div>
  );
}
