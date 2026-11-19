"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Mail } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert, Skeleton } from "@/components/ui/misc";
import * as account from "@/lib/api/account";
import { useErrorMessage, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { finishSignIn, safeNext } from "./sign-in-helpers";

/**
 * `/auth/add-email` — an old Nawy account that only had a phone number
 * (data-migration plan §5.2) signed in with an SMS code and must now add an
 * email and confirm it with the code sent there (`POST me/email` →
 * `POST me/email/verify`). Until then the rest of the account answers
 * `email_required`; afterwards email is the login.
 */
export function AddEmailForm() {
  const t = useTranslations("auth");
  const market = useMarket();
  const router = useRouter();
  const params = useSearchParams();
  const client = useQueryClient();
  const toMessage = useErrorMessage();
  const { user, isLoading } = useSession();
  const next = safeNext(params.get("next"), market.slug);
  const [email, setEmail] = useState("");
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (isLoading) return;
    if (!user) router.replace(`/${market.slug}/login?next=${encodeURIComponent(next)}`);
    else if (!user.requires_email) router.replace(next);
  }, [isLoading, user, router, market.slug, next]);

  const run = async (work: () => Promise<void>) => {
    setBusy(true);
    setError(null);
    try {
      await work();
    } catch (e) {
      setError(toMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const send = (event?: FormEvent) => {
    event?.preventDefault();
    void run(async () => {
      const { data } = await account.requestEmailChange(market.slug, email.trim());
      setSentTo(data.email);
      setCode("");
    });
  };

  const verify = (event: FormEvent) => {
    event.preventDefault();
    if (!sentTo) return;
    void run(async () => {
      const { data } = await account.confirmEmailChange(market.slug, sentTo, code.trim());
      await finishSignIn(client, market.slug, data, next);
    });
  };

  if (isLoading || !user || !user.requires_email) return <Skeleton className="mx-auto h-80 w-full max-w-md" />;

  return (
    <div className="mx-auto w-full max-w-md rounded-xl bg-surface p-6 shadow-lg md:p-8">
      <h1 className="text-h2 font-bold">{t("addEmailTitle")}</h1>
      <p className="mt-1 text-body text-secondary">{sentTo ? t("codeSent", { email: sentTo }) : t("addEmailHint")}</p>

      {sentTo ? (
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
            {busy ? t("verifying") : t("addEmailConfirm")}
          </Button>
          <div className="flex justify-between text-body-sm">
            <button type="button" className="text-link" onClick={() => send()} disabled={busy}>
              {t("resend")}
            </button>
            <button type="button" className="text-link" onClick={() => setSentTo(null)}>
              {t("changeEmail")}
            </button>
          </div>
        </form>
      ) : (
        <form onSubmit={send} className="mt-6 flex flex-col gap-4">
          <Field label={t("email")}>
            <div className="relative">
              <Mail aria-hidden className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
              <Input type="email" required autoComplete="email" maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t("emailPlaceholder")} className="ps-9" dir="ltr" autoFocus />
            </div>
          </Field>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Button type="submit" size="lg" disabled={busy || !email.trim()}>
            {busy ? t("sending") : t("sendCode")}
          </Button>
        </form>
      )}
    </div>
  );
}
