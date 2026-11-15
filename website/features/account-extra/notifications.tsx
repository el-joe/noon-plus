"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { Bell, CheckCheck } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/account";
import { formatDateTime } from "@/lib/format";
import { errorMessage, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

export const unreadKey = (market: string) => ["notifications-unread", market] as const;
const listKey = (market: string) => ["notifications", market] as const;

/** Unread notification count for the header badge (signed-in customers only, refreshed every minute). */
export function useUnreadNotifications() {
  const market = useMarket();
  const { isSignedIn } = useSession();
  return useQuery({
    queryKey: unreadKey(market.slug),
    enabled: isSignedIn,
    staleTime: 30_000,
    refetchInterval: 60_000,
    queryFn: async () => (await api.getUnreadCount(market.slug)).data.unread,
  });
}

/** Header bell with the unread badge. */
export function NotificationBell() {
  const t = useTranslations("header");
  const market = useMarket();
  const { isSignedIn } = useSession();
  const { data: unread = 0 } = useUnreadNotifications();
  if (!isSignedIn) return null;
  return (
    <Link href={`/${market.slug}/account/notifications`} className="relative flex items-center text-body font-medium" aria-label={unread > 0 ? `${t("notifications")} — ${t("unreadNotifications", { count: unread })}` : t("notifications")}>
      <Bell className="size-[22px]" />
      {unread > 0 ? (
        <span className="absolute -end-2 -top-1.5 min-w-4 rounded-full bg-danger px-1 text-center text-caption leading-4 text-white" aria-hidden>
          {unread > 99 ? "99+" : unread}
        </span>
      ) : null}
    </Link>
  );
}

/** `/account/notifications` — the customer's notification centre (`GET me/notifications`). */
export function NotificationsView() {
  const t = useTranslations("accountExtra.notifications");
  const tc = useTranslations("common");
  const market = useMarket();
  const client = useQueryClient();
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [page, setPage] = useState(1);
  const list = useQuery({
    queryKey: [...listKey(market.slug), unreadOnly, page],
    queryFn: async () => api.getNotifications(market.slug, { unread: unreadOnly ? 1 : undefined, page, per_page: 20 }),
  });
  const unread = Number(list.data?.meta.unread ?? 0);
  const lastPage = Number(list.data?.meta.last_page ?? 1);
  const refresh = (count: number) => {
    client.setQueryData(unreadKey(market.slug), count);
    client.invalidateQueries({ queryKey: listKey(market.slug) });
  };
  const markOne = useMutation({ mutationFn: async (id: number) => (await api.markNotificationRead(market.slug, id)).data, onSuccess: (row) => refresh(row.unread) });
  const markAll = useMutation({ mutationFn: async () => (await api.markAllNotificationsRead(market.slug)).data, onSuccess: (r) => refresh(r.unread) });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-h2 font-bold">{t("title")}</h1>
          {list.data ? <p className="text-body-sm text-secondary">{t("unreadCount", { count: unread })}</p> : null}
        </div>
        <Button variant="outline" size="sm" disabled={unread === 0 || markAll.isPending} onClick={() => markAll.mutate()}>
          <CheckCheck className="size-4" />
          {t("readAll")}
        </Button>
      </div>
      <div className="flex gap-2">
        {([false, true] as const).map((only) => (
          <Button
            key={String(only)}
            size="sm"
            variant={unreadOnly === only ? "dark" : "outline"}
            onClick={() => {
              setUnreadOnly(only);
              setPage(1);
            }}
          >
            {only ? t("unread") : t("all")}
          </Button>
        ))}
      </div>
      {list.error || markAll.error ? <Alert tone="danger">{errorMessage(list.error ?? markAll.error, tc("error"))}</Alert> : null}
      {list.isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {list.data && list.data.data.length === 0 ? <EmptyState icon={<Bell className="size-10" />} title={unreadOnly ? t("emptyUnread") : t("empty")} /> : null}
      {list.data && list.data.data.length > 0 ? (
        <Card className="p-0 md:p-0">
          <ul className="divide-y divide-line">
            {list.data.data.map((n) => {
              const target = api.notificationHref(n, market.slug);
              const body = (
                <>
                  <span className="flex items-start justify-between gap-3">
                    <span className={clsx("text-body", !n.read && "font-semibold")}>{n.title}</span>
                    {!n.read ? <span className="mt-1.5 size-2 shrink-0 rounded-full bg-danger" aria-label={t("new")} /> : null}
                  </span>
                  {n.body ? <span className="text-body-sm text-secondary">{n.body}</span> : null}
                  <span className="text-caption text-muted">{formatDateTime(n.created_at, market.locale, market.country.timeZone)}</span>
                </>
              );
              return (
                <li key={n.id} className={clsx("flex items-start gap-3 px-4 py-3", !n.read && "bg-brand-subtle/40")}>
                  {target ? (
                    <Link href={target} onClick={() => !n.read && markOne.mutate(n.id)} className="flex min-w-0 flex-1 flex-col gap-0.5 hover:underline">
                      {body}
                    </Link>
                  ) : (
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">{body}</div>
                  )}
                  {!n.read ? (
                    <Button size="sm" variant="ghost" onClick={() => markOne.mutate(n.id)} disabled={markOne.isPending} aria-label={t("markRead")}>
                      <CheckCheck className="size-4" />
                    </Button>
                  ) : null}
                </li>
              );
            })}
          </ul>
        </Card>
      ) : null}
      {lastPage > 1 ? (
        <div className="flex items-center justify-center gap-2">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t("previous")}
          </Button>
          <span className="text-body-sm text-secondary">
            {page} / {lastPage}
          </span>
          <Button size="sm" variant="outline" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {t("next")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
