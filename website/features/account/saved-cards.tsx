"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { CreditCard, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Alert, Badge, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/saved-cards";
import { useErrorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/**
 * `/account/cards` — saved cards (QA-C05, Figma "40 Cards"). Cards are
 * gateway tokens: only the brand, last 4 and expiry are shown; a card is
 * saved by paying with "save this card" at checkout, never typed in here.
 */
export function SavedCardsView() {
  const t = useTranslations("accountPlus.cards");
  const market = useMarket();
  const client = useQueryClient();
  const errorText = useErrorMessage();
  const key = ["saved-cards", market.slug];
  const list = useQuery({ queryKey: key, queryFn: async () => (await api.getSavedCards(market.slug)).data });
  const refresh = () => client.invalidateQueries({ queryKey: key });
  const makeDefault = useMutation({ mutationFn: (id: number) => api.makeDefaultCard(market.slug, id), onSuccess: refresh });
  const remove = useMutation({ mutationFn: (id: number) => api.deleteSavedCard(market.slug, id), onSuccess: refresh });
  const cards = list.data ?? [];
  const actionError = makeDefault.error ?? remove.error;

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-h2 font-bold">{t("title")}</h1>
        <p className="text-body-sm text-secondary">{t("intro")}</p>
      </div>
      {list.isLoading ? <Skeleton className="h-40 w-full" /> : null}
      {list.error ? <Alert tone="danger">{errorText(list.error)}</Alert> : null}
      {actionError ? <Alert tone="danger">{errorText(actionError)}</Alert> : null}
      {list.data && cards.length === 0 ? <EmptyState icon={<CreditCard className="size-10" />} title={t("empty")} body={t("emptyHint")} /> : null}
      <ul className="grid gap-3 md:grid-cols-2" aria-label={t("title")}>
        {cards.map((card) => {
          const brand = api.cardBrandName(card.brand) ?? t("card");
          const expiry = api.cardExpiry(card);
          return (
            <li key={card.id} data-testid="saved-card">
              <Card className={clsx("flex flex-col gap-3", card.expired && "opacity-80")}>
                <div className="flex items-start justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-subtle">
                      <CreditCard className="size-5 text-secondary" />
                    </span>
                    <div className="min-w-0">
                      <p className="flex flex-wrap items-center gap-2 text-body font-semibold">
                        <span>{brand}</span>
                        <span dir="ltr" className="tabular-nums">
                          •••• {card.last4}
                        </span>
                      </p>
                      {expiry ? (
                        <p className="text-body-sm text-secondary">{t(card.expired ? "expiredOn" : "expires", { date: `\u2066${expiry}\u2069` })}</p>
                      ) : null}
                    </div>
                  </div>
                  <div className="flex shrink-0 flex-col items-end gap-1">
                    {card.is_default ? <Badge tone="brand">{t("default")}</Badge> : null}
                    {card.expired ? <Badge tone="danger">{t("expired")}</Badge> : null}
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-4">
                  {!card.is_default ? (
                    <button
                      type="button"
                      onClick={() => makeDefault.mutate(card.id)}
                      disabled={makeDefault.isPending}
                      className="text-body-sm font-medium text-link disabled:opacity-50"
                    >
                      {t("makeDefault")}
                    </button>
                  ) : null}
                  <button
                    type="button"
                    onClick={() => window.confirm(t("confirmDelete", { last4: card.last4 })) && remove.mutate(card.id)}
                    disabled={remove.isPending}
                    aria-label={t("deleteLabel", { brand, last4: card.last4 })}
                    className="flex items-center gap-1 text-body-sm text-danger-text disabled:opacity-50"
                  >
                    <Trash2 className="size-3.5" /> {t("delete")}
                  </button>
                </div>
              </Card>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
