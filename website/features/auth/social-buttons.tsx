"use client";

import { useTranslations } from "next-intl";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { useErrorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

const SOCIAL_ERRORS = ["social_token_invalid", "social_email_unverified", "social_provider_unavailable", "social_state_invalid", "social_ticket_invalid", "account_suspended"] as const;

/** Localised text for a social sign-in error code (`?social_error=` or an API code). */
export function useSocialErrorText() {
  const t = useTranslations("auth.socialError");
  return (code: string, fallback?: string) =>
    (SOCIAL_ERRORS as readonly string[]).includes(code) ? t(code as (typeof SOCIAL_ERRORS)[number]) : (fallback ?? t("default"));
}

function GoogleIcon() {
  return (
    <svg aria-hidden viewBox="0 0 24 24" className="size-5">
      <path fill="#4285F4" d="M23.5 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.46a5.52 5.52 0 0 1-2.4 3.62v3h3.88c2.27-2.09 3.56-5.17 3.56-8.81z" />
      <path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.94-2.9l-3.88-3.02c-1.07.72-2.45 1.15-4.06 1.15-3.12 0-5.77-2.11-6.71-4.95H1.28v3.11A12 12 0 0 0 12 24z" />
      <path fill="#FBBC05" d="M5.29 14.28A7.2 7.2 0 0 1 4.91 12c0-.79.14-1.56.38-2.28V6.61H1.28A12 12 0 0 0 0 12c0 1.94.46 3.77 1.28 5.39l4.01-3.11z" />
      <path fill="#EA4335" d="M12 4.77c1.76 0 3.34.61 4.59 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.28 6.61l4.01 3.11C6.23 6.88 8.88 4.77 12 4.77z" />
    </svg>
  );
}

function AppleIcon() {
  return (
    <svg aria-hidden viewBox="0 0 24 24" className="size-5" fill="currentColor">
      <path d="M16.37 1.43c0 1.14-.49 2.27-1.18 3.08-.74.9-1.99 1.57-2.99 1.57-.12 0-.23-.02-.3-.03-.01-.06-.04-.22-.04-.39 0-1.15.57-2.27 1.21-2.98.8-.94 2.14-1.64 3.25-1.68.03.13.05.28.05.43zm4.56 15.71c-.03.07-.46 1.58-1.52 3.12-.94 1.34-1.94 2.71-3.43 2.71-1.52 0-1.9-.88-3.63-.88-1.7 0-2.3.91-3.67.91-1.38 0-2.33-1.26-3.43-2.8C4 18.38 2.96 15.57 2.96 12.92c0-4.28 2.8-6.55 5.55-6.55 1.45 0 2.68.95 3.6.95.87 0 2.22-1.01 3.9-1.01.61 0 2.89.06 4.37 2.19-.13.09-2.38 1.37-2.38 4.19 0 3.26 2.85 4.42 2.96 4.45z" />
    </svg>
  );
}

/**
 * "Continue with Google / Apple" (D9). Asks the API (through the BFF, as
 * JSON) for the provider URL and sends the browser there; the provider comes
 * back to the API, which redirects to `/{market}/auth/social/complete`.
 */
export function SocialButtons({ returnTo, onError }: { returnTo: string; onError: (message: string | null) => void }) {
  const t = useTranslations("auth");
  const market = useMarket();
  const toMessage = useErrorMessage();
  const socialErrorText = useSocialErrorText();
  const [pending, setPending] = useState<api.SocialProvider | null>(null);

  const start = async (provider: api.SocialProvider) => {
    setPending(provider);
    onError(null);
    try {
      const { data } = await api.socialRedirectUrl(market.slug, provider, returnTo);
      window.location.href = data.url;
    } catch (e) {
      onError(socialErrorText(e instanceof ApiError ? e.code : "", toMessage(e)));
      setPending(null);
    }
  };

  return (
    <div className="flex flex-col gap-3">
      {api.SOCIAL_PROVIDERS.map((provider) => (
        <Button key={provider} type="button" variant="outline" size="lg" disabled={pending !== null} onClick={() => start(provider)}>
          {provider === "google" ? <GoogleIcon /> : <AppleIcon />}
          {pending === provider ? t("redirecting") : provider === "google" ? t("continueGoogle") : t("continueApple")}
        </Button>
      ))}
    </div>
  );
}
