"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Monitor } from "lucide-react";
import { useTranslations } from "next-intl";
import { Alert, Badge, Card, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { formatDateTime, humanize } from "@/lib/format";
import { errorMessage, useSignOut } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

const OUTCOMES = ["succeeded", "wrong_code", "locked", "failed"] as const;

/** `/account/security` — signed-in devices + login history (Figma 52:7780). */
export function SecurityView() {
  const t = useTranslations();
  const market = useMarket();
  const client = useQueryClient();
  const signOut = useSignOut();
  const devices = useQuery({ queryKey: ["devices", market.slug], queryFn: async () => (await api.getDevices(market.slug)).data });
  const history = useQuery({ queryKey: ["login-history", market.slug], queryFn: async () => (await api.getLoginHistory(market.slug)).data });
  const revoke = useMutation({
    mutationFn: async (id: number) => api.revokeDevice(market.slug, id),
    onSuccess: () => client.invalidateQueries({ queryKey: ["devices", market.slug] }),
  });
  const when = (v: string | null) => formatDateTime(v, market.locale, market.country.timeZone);

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("account.security.title")}</h1>
      <Card className="p-0 md:p-0">
        <h2 className="border-b border-line px-5 py-3 text-h4 font-semibold">{t("account.security.devices")}</h2>
        {devices.isLoading ? <Skeleton className="m-5 h-24" /> : null}
        {devices.error ? <Alert tone="danger" className="m-5">{errorMessage(devices.error, t("common.error"))}</Alert> : null}
        <ul className="divide-y divide-line">
          {devices.data?.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center gap-4 px-5 py-3">
              <Monitor className="size-5 text-secondary" />
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-body font-medium">
                  {d.name}
                  {d.is_current ? <Badge tone="success">{t("account.security.thisDevice")}</Badge> : null}
                </p>
                <p className="text-body-sm text-secondary">{d.last_used_at ? t("account.security.lastUsed", { date: when(d.last_used_at) }) : t("account.security.never")}</p>
              </div>
              <button
                type="button"
                onClick={() => (d.is_current ? signOut.mutate() : revoke.mutate(d.id))}
                disabled={revoke.isPending || signOut.isPending}
                className="rounded-md border border-line px-3 py-1.5 text-body-sm font-medium hover:bg-subtle"
              >
                {t("account.security.signOutDevice")}
              </button>
            </li>
          ))}
        </ul>
      </Card>
      <Card className="p-0 md:p-0">
        <h2 className="border-b border-line px-5 py-3 text-h4 font-semibold">{t("account.security.history")}</h2>
        {history.isLoading ? <Skeleton className="m-5 h-24" /> : null}
        <ul className="divide-y divide-line">
          {history.data?.map((e, i) => {
            const outcome = e.outcome as (typeof OUTCOMES)[number];
            return (
              <li key={i} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-body-sm">
                <span>
                  <span className="font-medium">{OUTCOMES.includes(outcome) ? t(`account.security.outcome.${outcome}`) : humanize(e.outcome ?? "")}</span>
                  <span className="text-secondary"> · {e.channel ?? ""} · {e.ip_address ?? ""}</span>
                </span>
                <span className="text-secondary">{when(e.created_at)}</span>
              </li>
            );
          })}
        </ul>
      </Card>
    </div>
  );
}
