"use client";

import { MailCheck } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert, Spinner } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { resendApplicationEmailCode, verifyApplicationEmail, type Application } from "@/lib/api/portal";
import { useMarket } from "@/lib/market-context";
import { bannerError, fieldErrors } from "./errors";

/**
 * "Confirm your email" (QA-002): after the account step the API mails a
 * 6-digit code to the application email. The application cannot be submitted
 * until it is confirmed (`email_verified`). If the email already has a Nawy
 * account, the code is the owner's consent to add the workspace to it.
 */
export function EmailStep({ app, onVerified, onBack }: { app: Application; onVerified: (app: Application) => void; onBack: () => void }) {
  const t = useTranslations("portal");
  const tc = useTranslations("common");
  const market = useMarket();
  const [code, setCode] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [resent, setResent] = useState(false);
  const [wait, setWait] = useState(0);

  useEffect(() => {
    if (wait <= 0) return;
    const timer = window.setTimeout(() => setWait((s) => s - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [wait]);

  const describe = (e: unknown) => {
    if (e instanceof ApiError && e.status === 429) {
      if (e.retryAfter) setWait(e.retryAfter);
      return e.retryAfter ? tc("tooManyAttempts", { seconds: e.retryAfter }) : e.message;
    }
    return fieldErrors(e).code ?? bannerError(e, t("common.error")) ?? t("common.error");
  };

  const verify = async (event: FormEvent) => {
    event.preventDefault();
    setPending(true);
    setError(null);
    try {
      onVerified((await verifyApplicationEmail(market.slug, app.number, code.trim())).data);
    } catch (e) {
      setError(describe(e));
    } finally {
      setPending(false);
    }
  };

  const resend = async () => {
    setPending(true);
    setError(null);
    setResent(false);
    try {
      await resendApplicationEmailCode(market.slug, app.number);
      setResent(true);
      setCode("");
      setWait(60);
    } catch (e) {
      setError(describe(e));
    } finally {
      setPending(false);
    }
  };

  if (app.email_verified) {
    return (
      <div className="flex flex-col gap-4 rounded-lg border border-line bg-surface p-6">
        <Alert tone="success" className="flex items-center gap-2">
          <MailCheck className="size-4" /> {t("email.verified", { email: app.account.email ?? "" })}
        </Alert>
        <div className="flex gap-3">
          <Button variant="outline" onClick={onBack}>
            {t("common.back")}
          </Button>
          <Button onClick={() => onVerified(app)}>{t("wizard.saveContinue")}</Button>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={verify} className="flex flex-col gap-4 rounded-lg border border-line bg-surface p-6">
      <div>
        <h2 className="text-h3 font-semibold">{t("stepTitles.email")}</h2>
        <p className="mt-1 text-body text-secondary">{t("email.sent", { email: app.account.email ?? "" })}</p>
      </div>
      <Field label={t("email.code")} className="max-w-xs">
        <Input
          inputMode="numeric"
          autoComplete="one-time-code"
          required
          pattern="[0-9]{6}"
          maxLength={6}
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
          className="text-center text-h3 tracking-[0.4em]"
          dir="ltr"
          autoFocus
          aria-invalid={error ? true : undefined}
        />
      </Field>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {resent && !error ? <Alert tone="success">{t("email.resent")}</Alert> : null}
      <div className="flex flex-wrap items-center gap-3">
        <Button type="button" variant="outline" onClick={onBack} disabled={pending}>
          {t("common.back")}
        </Button>
        <Button type="submit" disabled={pending || code.length !== 6}>
          {pending ? <Spinner /> : null}
          {t("email.verify")}
        </Button>
        <button type="button" className="text-body-sm text-link disabled:text-muted" onClick={resend} disabled={pending || wait > 0}>
          {wait > 0 ? t("email.resendIn", { seconds: wait }) : t("email.resend")}
        </button>
      </div>
      <p className="text-body-sm text-secondary">{t("email.hint")}</p>
    </form>
  );
}
