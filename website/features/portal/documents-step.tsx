"use client";

import { FileText, Trash2, Upload } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/field";
import { Alert, Badge, Spinner } from "@/components/ui/misc";
import { removeApplicationDocument, uploadApplicationDocument, type Application, type ApplicationDocument } from "@/lib/api/portal";
import { useMarket } from "@/lib/market-context";
import { usePortalLabel } from "./use-portal-t";
import { bannerError, fieldErrors } from "./errors";

export const documentTone = (status: string) => (status === "approved" ? "success" : status === "rejected" || status === "expired" ? "danger" : "warning") as "success" | "danger" | "warning";

/** Documents step (Figma Seller reg — documents 73:594): one row per required document type of the country. */
export function DocumentsStep({
  app,
  onChange,
  onContinue,
  onBack,
  pending,
  error,
  submitLabel,
}: {
  app: Application;
  onChange: (app: Application) => void;
  onContinue: () => void;
  onBack: () => void;
  pending: boolean;
  error: string | null;
  submitLabel: string;
}) {
  const t = useTranslations("portal");
  const docs = app.documents.filter((d) => d.requirement !== "not_applicable");

  return (
    <div className="flex flex-col gap-5">
      <div className="rounded-lg border border-line bg-surface p-5">
        <h2 className="mb-1 text-h3 font-semibold">{t("steps.documents")}</h2>
        <p className="mb-4 text-body-sm text-secondary">{t("documents.intro")}</p>
        {docs.length === 0 ? (
          <p className="text-body text-secondary">{t("documents.none")}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-line">
            {docs.map((doc) => (
              <DocumentRow key={doc.document_type_id} app={app} doc={doc} onChange={onChange} />
            ))}
          </ul>
        )}
      </div>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <div className="flex items-center justify-between gap-3">
        <Button variant="ghost" onClick={onBack}>
          {t("common.back")}
        </Button>
        <Button size="lg" onClick={onContinue} disabled={pending}>
          {pending ? <Spinner /> : null}
          {submitLabel}
        </Button>
      </div>
    </div>
  );
}

function DocumentRow({ app, doc, onChange }: { app: Application; doc: ApplicationDocument; onChange: (app: Application) => void }) {
  const t = useTranslations("portal");
  const market = useMarket();
  const [file, setFile] = useState<File | null>(null);
  const [validUntil, setValidUntil] = useState("");
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);
  const label = usePortalLabel();
  const latest = doc.uploads[0];
  const needsUpload = !latest || latest.status === "rejected" || latest.status === "expired";

  const upload = async (event: FormEvent) => {
    event.preventDefault();
    if (!file) return setErrors({ file: t("documents.chooseFile") });
    const form = new FormData();
    form.append("document_type_id", String(doc.document_type_id));
    form.append("file", file);
    if (validUntil) form.append("valid_until", validUntil);
    setBusy(true);
    setErrors({});
    setBanner(null);
    try {
      const { data } = await uploadApplicationDocument(market.slug, app.number, form);
      onChange(data.application);
      setFile(null);
      setValidUntil("");
    } catch (error) {
      setErrors(fieldErrors(error));
      setBanner(bannerError(error, t("common.error")));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (id: number) => {
    setBusy(true);
    try {
      onChange((await removeApplicationDocument(market.slug, app.number, id)).data);
    } catch (error) {
      setBanner(bannerError(error, t("common.error")));
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className="flex flex-col gap-3 py-4">
      <div className="flex flex-wrap items-start gap-3">
        <FileText className="mt-0.5 size-5 shrink-0 text-secondary" />
        <div className="min-w-0 flex-1">
          <p className="text-body font-semibold">
            {doc.name}{" "}
            <Badge tone={doc.requirement === "mandatory" ? "brand" : "neutral"} className="ms-1">
              {t(`documents.requirement.${doc.requirement === "mandatory" ? "mandatory" : "optional"}`)}
            </Badge>
          </p>
          {doc.max_mb ? <p className="text-caption text-secondary">{t("documents.formats", { mb: doc.max_mb })}</p> : null}
        </div>
      </div>
      {doc.uploads.length > 0 ? (
        <ul className="flex flex-col gap-2 ps-8">
          {doc.uploads.map((u) => (
            <li key={u.id} className="flex flex-wrap items-center gap-2 text-body-sm">
              <span className="truncate" dir="ltr">
                {u.original_name ?? `#${u.id}`}
              </span>
              <Badge tone={documentTone(u.status)}>{label(`documents.status.${u.status}`, u.status)}</Badge>
              {u.valid_until ? <span className="text-secondary">{t("documents.validUntil", { date: u.valid_until })}</span> : null}
              {u.rejection_reason ? <span className="text-danger-text">{u.rejection_reason}</span> : null}
              {app.editable && u.status !== "approved" ? (
                <button type="button" onClick={() => remove(u.id)} disabled={busy} className="ms-auto inline-flex items-center gap-1 text-secondary hover:text-danger-text" aria-label={t("documents.remove")}>
                  <Trash2 className="size-4" />
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
      {app.editable && (needsUpload || doc.uploads.length === 0) ? (
        <form onSubmit={upload} className="flex flex-wrap items-end gap-3 ps-8">
          <label className="flex min-w-56 flex-1 flex-col gap-1">
            <span className="text-caption text-secondary">{t("documents.file")}</span>
            <Input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" className="pt-2" onChange={(e) => setFile(e.target.files?.[0] ?? null)} aria-invalid={errors.file ? true : undefined} />
          </label>
          {doc.has_expiry ? (
            <label className="flex flex-col gap-1">
              <span className="text-caption text-secondary">{t("documents.expiry")}</span>
              <Input type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} aria-invalid={errors.valid_until ? true : undefined} />
            </label>
          ) : null}
          <Button type="submit" variant="outline" disabled={busy}>
            {busy ? <Spinner /> : <Upload className="size-4" />}
            {latest ? t("documents.reupload") : t("documents.upload")}
          </Button>
          {errors.file || errors.valid_until || errors.document_type_id ? (
            <p className="w-full text-body-sm text-danger-text">{errors.file ?? errors.valid_until ?? errors.document_type_id}</p>
          ) : null}
        </form>
      ) : null}
      {banner ? <p className="ps-8 text-body-sm text-danger-text">{banner}</p> : null}
    </li>
  );
}
