"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { CalendarX, Crown, Tag, Truck } from "lucide-react";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { useCallback, useState } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Alert, Card, Skeleton } from "@/components/ui/misc";
import { ExtraStatus } from "@/features/account-extra/shared";
import * as api from "@/lib/api/shop";
import { formatDay, formatMoney } from "@/lib/format";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { followNextAction, PaymentMethodPicker } from "./payment";

/** `/membership` — Nawy One landing (pattern: app Nawy One 99:6173). */
export function MembershipLanding() {
  const t = useTranslations("shop.membership");
  const perks = [
    { icon: Truck, label: t("perkShipping") },
    { icon: Tag, label: t("perkDeals") },
    { icon: CalendarX, label: t("perkCancel") },
  ];
  return (
    <div className="flex flex-col gap-5">
      <section className="rounded-xl bg-ink p-6 text-white md:p-10">
        <p className="flex items-center gap-2 text-body font-semibold text-brand">
          <Crown className="size-5" />
          {t("title")}
        </p>
        <h1 className="mt-2 text-h1 font-bold">{t("subtitle")}</h1>
        <ul className="mt-5 grid gap-3 sm:grid-cols-3">
          {perks.map(({ icon: Icon, label }) => (
            <li key={label} className="flex items-center gap-3 rounded-md bg-white/10 p-3 text-body">
              <Icon className="size-5 shrink-0 text-brand" />
              {label}
            </li>
          ))}
        </ul>
      </section>
      <MembershipPanel />
    </div>
  );
}

/** Current membership + plans + subscribe / cancel (landing and `/account/membership`). */
export function MembershipPanel({ showTitle = false }: { showTitle?: boolean }) {
  const t = useTranslations("shop.membership");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const pathname = usePathname();
  const client = useQueryClient();
  const { user, isLoading: sessionLoading } = useSession();
  const key = ["membership", market.slug];
  const current = useQuery({ queryKey: key, enabled: !!user, queryFn: async () => (await api.getMembership(market.slug)).data });
  // Public since round 2: guests see the plans and prices too.
  const plans = useQuery({ queryKey: ["membership-plans", market.slug], queryFn: async () => (await api.getMembershipPlans(market.slug)).data });
  const [plan, setPlan] = useState<string | null>(null);
  const [method, setMethod] = useState<string | null>(null);
  const onMethod = useCallback((code: string | null) => setMethod(code), []);
  const chosen = plans.data?.find((p) => p.code === (plan ?? plans.data?.[0]?.code));

  const subscribe = useMutation({
    mutationFn: async () => (await api.subscribeMembership(market.slug, chosen!.code, method!)).data,
    onSuccess: (data) => {
      if (followNextAction(data.payment?.next_action)) return;
      client.setQueryData(key, data);
    },
  });
  const cancel = useMutation({
    mutationFn: async () => (await api.cancelMembership(market.slug)).data,
    onSuccess: (data) => client.setQueryData(key, data),
  });

  const title = showTitle ? <h1 className="text-h2 font-bold">{t("title")}</h1> : null;
  const price = (p: api.MembershipPlan) => (p.interval === "annual" ? t("perYear", { price: formatMoney(p.price) }) : t("perMonth", { price: formatMoney(p.price) }));
  if (sessionLoading) return <Skeleton className="h-48 w-full" />;
  if (!user) {
    return (
      <div className="flex flex-col gap-4">
        {title}
        {plans.data && plans.data.length > 0 ? (
          <Card>
            <h2 className="mb-3 text-h4 font-semibold">{t("plans")}</h2>
            <div className="grid gap-3 sm:grid-cols-2">
              {plans.data.map((p) => (
                <div key={p.code} className="flex flex-col gap-1 rounded-lg border border-line p-4">
                  <span className="text-body font-semibold">{p.name}</span>
                  <span className="text-h3 font-bold">{price(p)}</span>
                  {p.trial_days ? <span className="text-body-sm text-success-text">{t("trial", { days: p.trial_days })}</span> : null}
                  {p.free_shipping ? <span className="text-body-sm text-secondary">{t("freeShipping")}</span> : null}
                </div>
              ))}
            </div>
          </Card>
        ) : null}
        <Card className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-body font-medium">{t("signIn")}</p>
          <ButtonLink href={`${href("/login")}?next=${encodeURIComponent(pathname)}`}>{t("subscribe")}</ButtonLink>
        </Card>
      </div>
    );
  }

  const m = current.data;
  const isMember = !!m?.member;

  return (
    <div className="flex flex-col gap-4">
      {title}
      {current.isLoading ? <Skeleton className="h-28 w-full" /> : null}
      {current.error ? <Alert tone="danger">{errorMessage(current.error, tc("error"))}</Alert> : null}
      {m ? (
        <Card className={clsx(isMember && "border-brand bg-brand-subtle")}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="flex flex-col gap-1">
              <p className="flex items-center gap-2 text-h4 font-semibold">
                <Crown className="size-5" />
                {isMember ? t("member") : t("notMember")}
              </p>
              {m.status ? (
                <p className="flex items-center gap-2 text-body-sm">
                  {t("status")}: <ExtraStatus status={m.status} />
                </p>
              ) : null}
              {m.plan ? (
                <p className="text-body-sm">
                  {t("plan")}: {plans.data?.find((p) => p.code === m.plan)?.name ?? m.plan}
                </p>
              ) : null}
              {m.current_period_end ? (
                <p className="text-body-sm text-secondary">
                  {m.auto_renew ? t("renews", { date: formatDay(m.current_period_end, market.locale) }) : `${t("autoRenewOff")} · ${t("ends", { date: formatDay(m.current_period_end, market.locale) })}`}
                </p>
              ) : null}
              {m.benefits.free_shipping ? <p className="text-body-sm font-medium text-success-text">{t("freeShipping")}</p> : null}
            </div>
            {isMember && m.auto_renew ? (
              <Button
                variant="outline"
                size="sm"
                disabled={cancel.isPending}
                onClick={() => {
                  if (window.confirm(t("cancelConfirm"))) cancel.mutate();
                }}
              >
                {t("cancel")}
              </Button>
            ) : null}
          </div>
          {cancel.isSuccess ? <Alert tone="success" className="mt-3">{t("cancelled")}</Alert> : null}
          {subscribe.isSuccess && isMember ? <Alert tone="success" className="mt-3">{t("subscribed")}</Alert> : null}
          {cancel.error ? <Alert tone="danger" className="mt-3">{errorMessage(cancel.error, tc("error"))}</Alert> : null}
        </Card>
      ) : null}

      {m && !isMember ? (
        <Card>
          <h2 className="mb-3 text-h4 font-semibold">{t("plans")}</h2>
          {plans.isLoading ? <Skeleton className="h-28 w-full" /> : null}
          {plans.data && plans.data.length === 0 ? <p className="text-body text-secondary">{t("noPlans")}</p> : null}
          {plans.data && plans.data.length > 0 ? (
            <div className="flex flex-col gap-4">
              <div className="grid gap-3 sm:grid-cols-2">
                {plans.data.map((p) => (
                  <label key={p.code} className={clsx("flex cursor-pointer flex-col gap-1 rounded-lg border p-4", chosen?.code === p.code ? "border-brand bg-brand-subtle" : "border-line")}>
                    <span className="flex items-center gap-2">
                      <input type="radio" name="plan" checked={chosen?.code === p.code} onChange={() => setPlan(p.code)} />
                      <span className="text-body font-semibold">{p.name}</span>
                    </span>
                    <span className="text-h3 font-bold">{price(p)}</span>
                    {p.trial_days ? <span className="text-body-sm text-success-text">{t("trial", { days: p.trial_days })}</span> : null}
                    {p.free_shipping ? <span className="text-body-sm text-secondary">{t("freeShipping")}</span> : null}
                  </label>
                ))}
              </div>
              <PaymentMethodPicker amount={chosen?.price?.amount ?? null} value={method} onChange={onMethod} label={t("paymentMethod")} />
              {subscribe.error ? <Alert tone="danger">{errorMessage(subscribe.error, tc("error"))}</Alert> : null}
              <Button size="lg" className="self-start" disabled={!chosen || !method || subscribe.isPending} onClick={() => subscribe.mutate()}>
                {t("subscribe")}
              </Button>
            </div>
          ) : null}
          {plans.error ? <Alert tone="danger">{errorMessage(plans.error, tc("error"))}</Alert> : null}
        </Card>
      ) : null}
    </div>
  );
}
