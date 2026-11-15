"use client";

import { useTranslations } from "next-intl";
import { useState } from "react";
import { Modal } from "@/components/layout/modal";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import * as core from "@/lib/api/endpoints";
import type { RequiredContract } from "@/lib/api/schemas";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

/** Contracts listed by a 409 `customer_contract_required` (CustomerContractRequired::render). */
export function requiredContracts(error: unknown): RequiredContract[] | null {
  if (!(error instanceof ApiError) || error.status !== 409 || error.code !== "customer_contract_required") return null;
  const list = (error.details as { contracts?: unknown }).contracts;
  return Array.isArray(list) ? (list as RequiredContract[]) : [];
}

/** First server message for a form field (422 `error.fields`). */
export function fieldError(error: unknown, key: string): string | null {
  return error instanceof ApiError ? (error.fields[key]?.[0] ?? null) : null;
}

/**
 * Inquiry contract gate (FR-CLS-4, Figma 98:11859): the category needs the
 * customer purchase contract; the customer reads and click-signs it
 * (`POST contracts/{version}/accept`), then the action is retried. Guests
 * must sign in first.
 */
export function ContractModal({ contracts, onClose, onAccepted }: { contracts: RequiredContract[]; onClose: () => void; onAccepted: () => void }) {
  const t = useTranslations("classifieds.contract");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const { user, isSignedIn } = useSession();
  const [name, setName] = useState(user?.name ?? "");
  const [agree, setAgree] = useState(false);
  const [texts, setTexts] = useState<Record<number, string | null>>({});
  const [open, setOpen] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const read = async (id: number) => {
    setOpen(open === id ? null : id);
    if (texts[id] === undefined) {
      const text = await core
        .getContract(market.slug, id)
        .then((r) => r.data.text)
        .catch(() => null);
      setTexts((prev) => ({ ...prev, [id]: text }));
    }
  };

  const accept = async () => {
    setBusy(true);
    setError(null);
    try {
      for (const c of contracts) await core.acceptContract(market.slug, c.version_id, name.trim());
      onAccepted();
    } catch (e) {
      setError(errorMessage(e, tc("error")));
      setBusy(false);
    }
  };

  return (
    <Modal title={t("title")} onClose={onClose}>
      <p className="mb-4 text-body text-secondary">{t("hint")}</p>
      <ul className="mb-4 flex flex-col gap-2">
        {contracts.map((c) => (
          <li key={c.version_id} className="rounded-md border border-line p-3">
            <div className="flex items-center justify-between gap-3">
              <span className="text-body font-medium">
                {c.title} · v{c.version}
              </span>
              <button type="button" onClick={() => read(c.version_id)} className="text-body-sm text-link">
                {t("read")}
              </button>
            </div>
            {open === c.version_id ? (
              <div className="mt-2 max-h-56 overflow-y-auto whitespace-pre-line rounded-sm bg-subtle p-3 text-body-sm">{texts[c.version_id] === undefined ? tc("loading") : (texts[c.version_id] ?? t("unavailable"))}</div>
            ) : null}
          </li>
        ))}
      </ul>
      {!isSignedIn ? (
        <div className="flex flex-col gap-3">
          <Alert tone="info">{t("signInFirst")}</Alert>
          <ButtonLink href={`${href("/login")}?next=${encodeURIComponent(typeof window === "undefined" ? "" : window.location.pathname)}`}>{t("signIn")}</ButtonLink>
        </div>
      ) : (
        <>
          <Field label={t("fullName")}>
            <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={160} required />
          </Field>
          <label className="mt-3 flex items-center gap-2 text-body">
            <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
            {t("accept")}
          </label>
          {error ? (
            <Alert tone="danger" className="mt-3">
              {error}
            </Alert>
          ) : null}
          <Button size="lg" className="mt-4 w-full" disabled={!agree || name.trim().length < 3 || busy} onClick={accept}>
            {t("acceptAndContinue")}
          </Button>
        </>
      )}
    </Modal>
  );
}
