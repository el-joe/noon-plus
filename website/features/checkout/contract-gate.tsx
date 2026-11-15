"use client";

import { useTranslations } from "next-intl";
import { useState } from "react";
import { Modal } from "@/components/layout/modal";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import type { RequiredContract } from "@/lib/api/schemas";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/**
 * Checkout contract gate (FR-CHK-4, Figma 102:16764): POST orders answered
 * 409 `customer_contract_required`; the customer reads and click-signs each
 * contract (`POST contracts/{version}/accept`), then the order is retried.
 */
export function ContractGate({ contracts, defaultName, onClose, onAccepted }: { contracts: RequiredContract[]; defaultName: string; onClose: () => void; onAccepted: () => void }) {
  const t = useTranslations();
  const market = useMarket();
  const [name, setName] = useState(defaultName);
  const [agree, setAgree] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const [texts, setTexts] = useState<Record<number, string | null>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const read = async (id: number) => {
    setOpen(open === id ? null : id);
    if (texts[id] === undefined) {
      const text = await api
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
      for (const contract of contracts) await api.acceptContract(market.slug, contract.version_id, name.trim());
      onAccepted();
    } catch (e) {
      setError(errorMessage(e, t("common.error")));
      setBusy(false);
    }
  };

  return (
    <Modal title={t("checkout.contractTitle")} onClose={onClose}>
      <p className="mb-4 text-body text-secondary">{t("checkout.contractHint")}</p>
      <ul className="mb-4 flex flex-col gap-2">
        {contracts.map((c) => (
          <li key={c.version_id} className="rounded-md border border-line p-3">
            <div className="flex items-center justify-between gap-3">
              <span className="text-body font-medium">
                {c.title} · v{c.version}
              </span>
              <button type="button" onClick={() => read(c.version_id)} className="text-body-sm text-link">
                {t("checkout.readContract")}
              </button>
            </div>
            {open === c.version_id ? (
              <div className="mt-2 max-h-56 overflow-y-auto whitespace-pre-line rounded-sm bg-subtle p-3 text-body-sm">{texts[c.version_id] ?? t("common.loading")}</div>
            ) : null}
          </li>
        ))}
      </ul>
      <Field label={t("checkout.fullName")}>
        <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={160} required />
      </Field>
      <label className="mt-3 flex items-center gap-2 text-body">
        <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
        {t("checkout.accept")}
      </label>
      {error ? <Alert tone="danger" className="mt-3">{error}</Alert> : null}
      <Button size="lg" className="mt-4 w-full" disabled={!agree || !name.trim() || busy} onClick={accept}>
        {t("common.continue")}
      </Button>
    </Modal>
  );
}
