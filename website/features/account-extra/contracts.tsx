"use client";

import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { FileSignature } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, Badge, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import { ContractGate } from "@/features/checkout/contract-gate";
import * as account from "@/lib/api/account";
import * as api from "@/lib/api/account-extra";
import { formatDay } from "@/lib/format";
import { getContract } from "@/lib/api/endpoints";
import type { RequiredContract } from "@/lib/api/schemas";
import { errorMessage, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/** Kinds a customer can click-sign from the storefront (ContractApiController@accept). */
const SIGNABLE = new Set(["customer_purchase", "marketer_onboarding", "trip"]);

/**
 * `/account/contracts` (Figma My contracts 52:6780): contracts waiting for
 * consent from `GET me/contracts`, read via `contracts/{version}`, accepted
 * with the checkout click-wrap; then the signed history (`GET me/contracts/signed`).
 */
export function ContractsView() {
  const t = useTranslations("accountExtra.contracts");
  const tc = useTranslations("common");
  const market = useMarket();
  const client = useQueryClient();
  const { user } = useSession();
  const key = ["my-contracts", market.slug];
  const { data, isLoading, error } = useQuery({ queryKey: key, queryFn: async () => (await api.getMyContracts(market.slug)).data });
  const [open, setOpen] = useState<number | null>(null);
  const [texts, setTexts] = useState<Record<number, string | null>>({});
  const [signing, setSigning] = useState<RequiredContract | null>(null);
  const [signed, setSigned] = useState(false);

  const read = async (id: number) => {
    setOpen(open === id ? null : id);
    if (texts[id] === undefined) {
      const text = await getContract(market.slug, id)
        .then((r) => r.data.text)
        .catch(() => null);
      setTexts((prev) => ({ ...prev, [id]: text }));
    }
  };
  const kind = (k: string) => (t.has(`kind.${k as "trip"}`) ? t(`kind.${k as "trip"}`) : k);

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("title")}</h1>
      <p className="text-body text-secondary">{t("hint")}</p>
      {signed ? <Alert tone="success">{t("signed")}</Alert> : null}
      {isLoading ? <Skeleton className="h-40 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
      {data && data.contracts.length === 0 ? <EmptyState icon={<FileSignature className="size-10" />} title={t("noneRequired")} body={t("history")} /> : null}
      {data && data.contracts.length > 0 ? (
        <Card>
          <h2 className="mb-3 text-h4 font-semibold">{t("pending")}</h2>
          <ul className="flex flex-col gap-3">
            {data.contracts.map((c, i) => {
              const id = c.version_id ?? null;
              const canSign = id !== null && c.version !== undefined && SIGNABLE.has(c.kind);
              return (
                <li key={`${c.kind}-${id ?? i}`} className="rounded-md border border-line p-3">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <p className="text-body font-medium">{c.title}</p>
                      <p className="flex flex-wrap items-center gap-2 text-body-sm text-secondary">
                        <Badge tone="warning">{kind(c.kind)}</Badge>
                        {c.version !== undefined ? t("version", { version: String(c.version) }) : null}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      {id !== null ? (
                        <Button variant="ghost" size="sm" onClick={() => read(id)}>
                          {open === id ? t("hide") : t("read")}
                        </Button>
                      ) : null}
                      {canSign ? (
                        <Button size="sm" onClick={() => setSigning({ version_id: id, kind: c.kind, title: c.title, version: c.version! })}>
                          {t("sign")}
                        </Button>
                      ) : (
                        <span className="text-body-sm text-secondary">{t("openInPanel")}</span>
                      )}
                    </div>
                  </div>
                  {id !== null && open === id ? (
                    <div className="mt-2 max-h-72 overflow-y-auto whitespace-pre-line rounded-sm bg-subtle p-3 text-body-sm">{texts[id] ?? tc("loading")}</div>
                  ) : null}
                </li>
              );
            })}
          </ul>
          <p className="mt-3 text-body-sm text-secondary">{t("history")}</p>
        </Card>
      ) : null}
      {signing ? (
        <ContractGate
          contracts={[signing]}
          defaultName={user?.name ?? ""}
          onClose={() => setSigning(null)}
          onAccepted={() => {
            setSigning(null);
            setSigned(true);
            client.invalidateQueries({ queryKey: key });
            client.invalidateQueries({ queryKey: ["signed-contracts", market.slug] });
          }}
        />
      ) : null}
      <SignedContracts />
    </div>
  );
}

const SIGNED_STATUSES = ["active", "superseded", "revoked"] as const;

/** Signed contracts history (`GET me/contracts/signed`, paginated). */
function SignedContracts() {
  const t = useTranslations("accountExtra.contracts");
  const tc = useTranslations("common");
  const market = useMarket();
  const signed = useInfiniteQuery({
    queryKey: ["signed-contracts", market.slug],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => account.getSignedContracts(market.slug, pageParam),
    getNextPageParam: (last) => {
      const page = Number(last.meta.page ?? last.meta.current_page ?? 1);
      return page < Number(last.meta.last_page ?? 1) ? page + 1 : undefined;
    },
  });
  const rows = signed.data?.pages.flatMap((p) => p.data) ?? [];
  const kind = (k: string | null) => (!k ? "" : t.has(`kind.${k as "trip"}`) ? t(`kind.${k as "trip"}`) : k);
  const [open, setOpen] = useState<number | null>(null);
  const [texts, setTexts] = useState<Record<number, string | null>>({});
  const read = async (rowId: number, versionId: number) => {
    setOpen(open === rowId ? null : rowId);
    if (texts[versionId] === undefined) {
      const text = await getContract(market.slug, versionId)
        .then((r) => r.data.text)
        .catch(() => null);
      setTexts((prev) => ({ ...prev, [versionId]: text }));
    }
  };

  return (
    <Card>
      <h2 className="mb-3 text-h4 font-semibold">{t("signedTitle")}</h2>
      {signed.isLoading ? <Skeleton className="h-24 w-full" /> : null}
      {signed.error ? <Alert tone="danger">{errorMessage(signed.error, tc("error"))}</Alert> : null}
      {signed.data && rows.length === 0 ? <p className="text-body text-secondary">{t("signedEmpty")}</p> : null}
      <ul className="flex flex-col divide-y divide-line">
        {rows.map((c) => {
          const status = (SIGNED_STATUSES as readonly string[]).includes(c.status) ? t(`signedStatus.${c.status as (typeof SIGNED_STATUSES)[number]}`) : c.status;
          const date = formatDay(c.signed_at, market.locale);
          return (
            <li key={c.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
              <div className="min-w-0">
                <p className="text-body font-medium">{c.title ?? (kind(c.kind) || t("signedTitle"))}</p>
                <p className="flex flex-wrap items-center gap-2 text-body-sm text-secondary">
                  <Badge tone={c.status === "active" ? "success" : "neutral"}>{status}</Badge>
                  <span>{kind(c.kind)}</span>
                  {c.version !== null ? <span>{t("version", { version: String(c.version) })}</span> : null}
                </p>
                <p className="text-body-sm text-secondary">{c.full_name ? t("signedOn", { date, name: c.full_name }) : t("signedOnNoName", { date })}</p>
                {c.superseded_at ? <p className="text-caption text-muted">{t("supersededOn", { date: formatDay(c.superseded_at, market.locale) })}</p> : null}
                {c.sha256 ? (
                  <p className="truncate text-caption text-muted" dir="ltr" title={c.sha256}>
                    {t("fingerprint")}: {c.sha256.slice(0, 16)}…
                  </p>
                ) : null}
              </div>
              {c.version_id !== null ? (
                <Button variant="ghost" size="sm" onClick={() => read(c.id, c.version_id!)}>
                  {open === c.id ? t("hide") : t("read")}
                </Button>
              ) : null}
              {c.version_id !== null && open === c.id ? (
                <div className="max-h-72 w-full overflow-y-auto whitespace-pre-line rounded-sm bg-subtle p-3 text-body-sm">{texts[c.version_id] === undefined ? tc("loading") : (texts[c.version_id] ?? tc("error"))}</div>
              ) : null}
            </li>
          );
        })}
      </ul>
      {signed.hasNextPage ? (
        <Button variant="outline" size="sm" className="mt-3" onClick={() => signed.fetchNextPage()} disabled={signed.isFetchingNextPage}>
          {t("more")}
        </Button>
      ) : null}
    </Card>
  );
}
