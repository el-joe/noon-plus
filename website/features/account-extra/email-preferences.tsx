"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { MailCheck, MailX } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, Card, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/email-preferences";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

const prefsKey = (market: string) => ["email-preferences", market] as const;

/**
 * `/account/notifications/settings` — which emails the customer receives.
 * Transactional and security emails are always on (shown locked); reminders,
 * wishlist alerts / review requests and cart reminders (opt-in) can be turned
 * off. Texts come from the API in the customer's language.
 */
export function EmailPreferencesView() {
  const t = useTranslations("accountExtra.emailPreferences");
  const tc = useTranslations("common");
  const market = useMarket();
  const client = useQueryClient();
  const query = useQuery({ queryKey: prefsKey(market.slug), queryFn: async () => (await api.getEmailPreferences(market.slug)).data.categories });
  const [draft, setDraft] = useState<Record<string, boolean>>({});
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (query.data) setDraft(Object.fromEntries(query.data.map((c) => [c.topic, c.enabled])));
  }, [query.data]);

  const save = useMutation({
    mutationFn: async () =>
      (
        await api.saveEmailPreferences(
          market.slug,
          (query.data ?? []).filter((c) => !c.locked).map((c) => ({ topic: c.topic, enabled: draft[c.topic] ?? c.enabled })),
        )
      ).data.categories,
    onSuccess: (categories) => {
      client.setQueryData(prefsKey(market.slug), categories);
      setSaved(true);
    },
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-h2 font-bold">{t("title")}</h1>
          <p className="text-body-sm text-secondary">{t("intro")}</p>
        </div>
        <Link href={`/${market.slug}/account/notifications`} className="text-body-sm font-medium text-brand hover:underline">
          {t("back")}
        </Link>
      </div>
      {query.error || save.error ? <Alert tone="danger">{errorMessage(query.error ?? save.error, tc("error"))}</Alert> : null}
      {saved && !save.isPending ? <Alert tone="success">{t("saved")}</Alert> : null}
      {query.isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {query.data ? (
        <Card className="p-0 md:p-0">
          <ul className="divide-y divide-line">
            {query.data.map((category) => (
              <li key={category.topic} className="flex items-start justify-between gap-4 px-4 py-3" data-testid="email-category">
                <div className="flex min-w-0 flex-col gap-0.5">
                  <span className="text-body font-medium">{category.label}</span>
                  <span className="text-body-sm text-secondary">{category.description}</span>
                </div>
                {category.locked ? (
                  <span className="shrink-0 rounded-full bg-surface-subtle px-2 py-0.5 text-caption text-secondary">{t("alwaysOn")}</span>
                ) : (
                  <label className="flex shrink-0 items-center gap-2 text-body-sm">
                    <input
                      type="checkbox"
                      className="size-4 accent-brand"
                      checked={draft[category.topic] ?? category.enabled}
                      aria-label={category.label}
                      onChange={(event) => {
                        setSaved(false);
                        setDraft({ ...draft, [category.topic]: event.target.checked });
                      }}
                    />
                    {t("receive")}
                  </label>
                )}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
      {query.data ? (
        <div>
          <Button onClick={() => save.mutate()} disabled={save.isPending}>
            {t("save")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

/**
 * `/unsubscribe?token=…` — landing page of the one-click unsubscribe link in
 * reminder / alert emails. Works signed out (the token is signed by the API);
 * shows the topic and lets the reader unsubscribe or subscribe again.
 */
export function UnsubscribeView({ token }: { token: string | null }) {
  const t = useTranslations("accountExtra.unsubscribe");
  const tc = useTranslations("common");
  const market = useMarket();
  const client = useQueryClient();
  const key = ["unsubscribe", token] as const;
  const info = useQuery({ queryKey: key, enabled: token !== null, retry: false, queryFn: async () => (await api.getUnsubscribe(token as string)).data });
  const change = useMutation({
    mutationFn: async (subscribe: boolean) => (await (subscribe ? api.resubscribe(token as string) : api.unsubscribe(token as string))).data,
    onSuccess: (data) => client.setQueryData(key, data),
  });

  if (token === null || info.error) {
    return (
      <Card className="mx-auto flex max-w-md flex-col gap-3 text-center">
        <MailX className="mx-auto size-10 text-secondary" />
        <h1 className="text-h3 font-bold">{t("invalidTitle")}</h1>
        <p className="text-body-sm text-secondary">{t("invalid")}</p>
      </Card>
    );
  }

  if (info.isLoading || !info.data) return <Skeleton className="mx-auto h-64 max-w-md" />;

  const data = info.data;
  return (
    <Card className="mx-auto flex max-w-md flex-col gap-4 text-center">
      {data.subscribed ? <MailCheck className="mx-auto size-10 text-brand" /> : <MailX className="mx-auto size-10 text-secondary" />}
      <h1 className="text-h3 font-bold">{data.subscribed ? t("title") : t("doneTitle")}</h1>
      <p className="text-body-sm text-secondary">{data.subscribed ? t("question", { topic: data.label }) : t("done", { topic: data.label })}</p>
      {data.email ? <p className="text-caption text-muted" dir="ltr">{data.email}</p> : null}
      {change.error ? <Alert tone="danger">{errorMessage(change.error, tc("error"))}</Alert> : null}
      <Button variant={data.subscribed ? "primary" : "outline"} disabled={change.isPending} onClick={() => change.mutate(!data.subscribed)}>
        {data.subscribed ? t("confirm") : t("resubscribe")}
      </Button>
      <p className="text-caption text-secondary">{t("note")}</p>
      <Link href={`/${market.slug}/account/notifications/settings`} className="text-body-sm font-medium text-brand hover:underline">
        {t("manage")}
      </Link>
    </Card>
  );
}
