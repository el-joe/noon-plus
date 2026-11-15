"use client";

import { useQuery } from "@tanstack/react-query";
import { Check } from "lucide-react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { ButtonLink } from "@/components/ui/button";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { errorMessage, useMounted } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { OrderShipmentsCard, OrderTotalsCard } from "./order-summary";

/** `/checkout/success?order=` — Order confirmation (Figma 68:591). */
export function ConfirmationView() {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const mounted = useMounted();
  const number = useSearchParams().get("order") ?? "";
  const order = useQuery({
    queryKey: ["order", market.slug, number],
    enabled: mounted && !!number,
    queryFn: async () => (await api.getOrder(market.slug, number)).data,
  });

  if (!number) return <EmptyState title={t("common.notFoundTitle")} action={<ButtonLink href={href("/")}>{t("common.goHome")}</ButtonLink>} />;
  if (order.error) return <Alert tone="danger">{errorMessage(order.error, t("common.error"))}</Alert>;
  if (!order.data) return <Skeleton className="mx-auto h-96 max-w-2xl" />;

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <Card className="flex flex-col items-center gap-3 py-8 text-center">
        <span className="flex size-14 items-center justify-center rounded-full bg-success-subtle text-success-text">
          <Check className="size-7" />
        </span>
        <h1 className="text-h2 font-bold">{t("confirmation.title")}</h1>
        <p className="text-body text-secondary">{t("confirmation.order", { number: order.data.number })}</p>
        <div className="flex gap-3">
          <ButtonLink href={href(`/account/orders/${encodeURIComponent(order.data.number)}`)}>{t("confirmation.track")}</ButtonLink>
          <ButtonLink href={href("/")} variant="outline">
            {t("confirmation.continue")}
          </ButtonLink>
        </div>
      </Card>
      <OrderShipmentsCard order={order.data} />
      <OrderTotalsCard order={order.data} />
      <Alert tone="info" className="text-body-sm">
        {t("confirmation.deliveryCodeNote")}
      </Alert>
    </div>
  );
}
