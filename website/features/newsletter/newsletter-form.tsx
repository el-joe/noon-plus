"use client";

import { useMutation } from "@tanstack/react-query";
import clsx from "clsx";
import { CheckCircle2, Mail } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/field";
import { fieldErrors } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/**
 * Newsletter opt-in (G-W12): `POST newsletter/subscribe` always answers 202
 * (same reply whether or not the address is known); with double opt-in the
 * visitor confirms from the email link (`/newsletter/confirm`).
 */
export function NewsletterForm({ source = "storefront", className, tone = "light" }: { source?: string; className?: string; tone?: "light" | "dark" }) {
  const t = useTranslations();
  const market = useMarket();
  const [error, setError] = useState<string | null>(null);
  const subscribe = useMutation({
    mutationFn: async (email: string) => (await api.subscribeNewsletter(market.slug, email, source)).data,
    onError: (e) => setError(fieldErrors(e).email ?? errorMessage(e, t("common.error"))),
  });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    const email = String(new FormData(event.currentTarget).get("email") ?? "").trim();
    if (email) subscribe.mutate(email);
  };

  if (subscribe.isSuccess) {
    return (
      <p role="status" className={clsx("flex items-center gap-2 text-body font-medium", tone === "dark" ? "text-white" : "text-success-text", className)}>
        <CheckCircle2 className="size-5 shrink-0" />
        {subscribe.data.status === "subscribed" ? t("newsletter.confirmed") : t("newsletter.sent")}
      </p>
    );
  }

  return (
    <form onSubmit={submit} className={clsx("flex w-full max-w-md flex-col gap-1.5", className)} noValidate>
      <div className="flex gap-2">
        <label className="relative flex-1">
          <span className="sr-only">{t("newsletter.placeholder")}</span>
          <Mail className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input name="email" type="email" required autoComplete="email" dir="ltr" placeholder={t("newsletter.placeholder")} className="ps-9 text-start" />
        </label>
        <Button type="submit" disabled={subscribe.isPending} variant={tone === "dark" ? "primary" : "dark"}>
          {subscribe.isPending ? t("newsletter.sending") : t("newsletter.subscribe")}
        </Button>
      </div>
      {error ? <p className={clsx("text-body-sm", tone === "dark" ? "text-brand" : "text-danger-text")}>{error}</p> : null}
    </form>
  );
}
