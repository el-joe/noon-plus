"use client";

import { useQueryClient } from "@tanstack/react-query";
import { XCircle } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Spinner } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { useErrorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { deviceName, finishSignIn } from "./sign-in-helpers";
import { useSocialErrorText } from "./social-buttons";

/** Exchanges the Google / Apple sign-in ticket once, then goes to `return_to`. */
export function SocialComplete({ ticket, returnTo }: { ticket: string | null; returnTo: string | null }) {
  const t = useTranslations("auth");
  const market = useMarket();
  const client = useQueryClient();
  const toMessage = useErrorMessage();
  const socialErrorText = useSocialErrorText();
  const started = useRef(false);
  const [error, setError] = useState<string | null>(ticket ? null : socialErrorText("social_ticket_invalid"));

  useEffect(() => {
    if (!ticket || started.current) return;
    started.current = true;
    api
      .exchangeSocialTicket(market.slug, ticket, deviceName())
      .then(({ data }) => finishSignIn(client, market.slug, data.user, returnTo))
      .catch((e: unknown) => setError(socialErrorText(e instanceof ApiError ? e.code : "", toMessage(e))));
    // Run once per ticket.
  }, [ticket]);

  return (
    <div className="mx-auto flex max-w-md flex-col items-center gap-4 rounded-xl bg-surface p-8 text-center shadow-lg">
      {error ? (
        <>
          <XCircle aria-hidden className="size-10 text-danger-text" />
          <h1 className="text-h3 font-bold">{t("socialFailedTitle")}</h1>
          <p role="alert" className="text-body text-secondary">
            {error}
          </p>
          <ButtonLink href={`/${market.slug}/login`}>{t("backToSignIn")}</ButtonLink>
        </>
      ) : (
        <>
          <Spinner className="size-8 text-brand-text" />
          <h1 className="text-h3 font-bold">{t("socialCompleteTitle")}</h1>
          <p role="status" className="text-body text-secondary">
            {t("socialCompleting")}
          </p>
        </>
      )}
    </div>
  );
}
