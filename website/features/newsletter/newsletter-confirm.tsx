"use client";

import { useMutation } from "@tanstack/react-query";
import { CheckCircle2, MailX, XCircle } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Spinner } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { useHref, useMarket } from "@/lib/market-context";

/** Confirms (or, with `action=unsubscribe`, cancels) a newsletter subscription from the email link. */
export function NewsletterConfirm({ token, action }: { token: string | null; action: "confirm" | "unsubscribe" }) {
  const t = useTranslations("newsletter");
  const market = useMarket();
  const href = useHref();
  const started = useRef(false);
  const run = useMutation({
    mutationFn: async (tok: string) => (action === "unsubscribe" ? api.unsubscribeNewsletter(market.slug, tok) : api.confirmNewsletter(market.slug, tok)),
  });
  const { mutate } = run;

  useEffect(() => {
    if (token && !started.current) {
      started.current = true;
      mutate(token);
    }
  }, [token, mutate]);

  let icon = <Spinner className="size-8 text-brand-text" />;
  let message = action === "unsubscribe" ? t("unsubscribing") : t("confirming");
  if (!token) {
    icon = <XCircle className="size-10 text-danger-text" />;
    message = t("missingToken");
  } else if (run.isError) {
    icon = <XCircle className="size-10 text-danger-text" />;
    message = t("invalid");
  } else if (run.isSuccess) {
    icon = action === "unsubscribe" ? <MailX className="size-10 text-secondary" /> : <CheckCircle2 className="size-10 text-success-text" />;
    message = action === "unsubscribe" ? t("unsubscribed") : t("confirmed");
  }

  return (
    <div className="mx-auto flex max-w-md flex-col items-center gap-4 rounded-lg border border-line bg-surface px-6 py-12 text-center">
      {icon}
      <h1 className="text-h3 font-semibold">{t("confirmTitle")}</h1>
      <p role="status" className="text-body text-secondary">
        {message}
      </p>
      {run.isSuccess || run.isError || !token ? <ButtonLink href={href("/")}>{t("backHome")}</ButtonLink> : null}
    </div>
  );
}
