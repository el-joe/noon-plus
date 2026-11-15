"use client";

import clsx from "clsx";
import { AlertCircle, CheckCircle2, Circle, XCircle } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Alert, Badge, EmptyState, Skeleton, Spinner } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { forgetApplication, getApplication, isApplicationType, myApplicationNumbers, withdrawApplication, type Application } from "@/lib/api/portal";
import { useHref, useMarket } from "@/lib/market-context";
import { usePortalLabel } from "./use-portal-t";
import { documentTone } from "./documents-step";
import { bannerError } from "./errors";

const STATUS_TONE: Record<string, "neutral" | "success" | "warning" | "danger" | "info"> = {
  draft: "neutral",
  submitted: "info",
  under_review: "info",
  changes_requested: "warning",
  approved: "success",
  rejected: "danger",
  withdrawn: "neutral",
};

/**
 * Application status (Figma Application status 73:1273; Approved 100:18238,
 * Rejected 100:18417, More documents 100:18594). Without `number` it lists
 * the applications this browser has started in this country.
 */
export function ApplicationStatusView({ number }: { number?: string }) {
  const t = useTranslations("portal");
  const market = useMarket();
  const [apps, setApps] = useState<Application[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const numbers = number ? [number] : (await myApplicationNumbers(market.slug)).data;
        const loaded: Application[] = [];
        for (const n of numbers) {
          try {
            loaded.push((await getApplication(market.slug, n)).data);
          } catch (e) {
            if (number) throw e;
          }
        }
        if (!cancelled) setApps(loaded);
      } catch (e) {
        if (!cancelled) setError(e instanceof ApiError && e.status === 404 ? t("status.notFound") : t("common.error"));
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- load once per number
  }, [market.slug, number]);

  if (error) return <Alert tone="danger">{error}</Alert>;
  if (!apps) return <Skeleton className="h-96 w-full" />;
  if (apps.length === 0) {
    return <EmptyState title={t("status.emptyTitle")} body={t("status.emptyBody")} action={<ButtonLink href={`/${market.slug}/join`}>{t("status.startApplication")}</ButtonLink>} />;
  }
  return (
    <div className="flex flex-col gap-6">
      {apps.map((app) => (
        <ApplicationCard key={app.number} initial={app} />
      ))}
    </div>
  );
}

function ApplicationCard({ initial }: { initial: Application }) {
  const t = useTranslations("portal");
  const format = useFormatter();
  const market = useMarket();
  const href = useHref();
  const label = usePortalLabel();
  const [app, setApp] = useState(initial);
  const [pending, setPending] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [banner, setBanner] = useState<string | null>(null);
  const type = isApplicationType(app.type) ? app.type : "vendor";
  const date = (iso: string | null) => (iso ? format.dateTime(new Date(iso), { dateStyle: "medium", timeStyle: "short" }) : null);
  const wizardHref = `${href(`/join/${type}`)}?number=${encodeURIComponent(app.number)}`;
  const flagged = new Set(app.requested_changes?.document_ids ?? []);

  const withdraw = async () => {
    setPending(true);
    setBanner(null);
    try {
      setApp((await withdrawApplication(market.slug, app.number)).data);
      setConfirming(false);
    } catch (error) {
      setBanner(bannerError(error, t("common.error")));
    } finally {
      setPending(false);
    }
  };

  const forget = async () => {
    await forgetApplication(market.slug, app.number).catch(() => null);
    window.location.reload();
  };

  const inReview = app.status === "submitted" || app.status === "under_review";
  const decided = app.status === "approved" || app.status === "rejected";
  const timeline: { key: string; label: string; state: "done" | "attention" | "waiting" | "failed"; note?: string | null; action?: React.ReactNode }[] = [
    ...app.steps
      .filter((s) => s.key !== "agreement")
      .map((s) => {
        const attention = s.key === "documents" ? app.documents.some((d) => d.uploads[0]?.status === "rejected" || flagged.has(d.uploads[0]?.id ?? -1)) : (app.requested_changes?.steps ?? []).includes(s.key);
        return {
          key: s.key,
          label: label(`steps.${s.key}`, s.key),
          state: (attention ? "attention" : s.completed || (s.key === "documents" && app.documents.every((d) => !d.missing)) ? "done" : "waiting") as "attention" | "done" | "waiting",
          note: attention ? t("status.needsAttention") : null,
          action: attention && app.editable ? <ButtonLink href={wizardHref} size="sm">{t("status.fix")}</ButtonLink> : null,
        };
      }),
    { key: "agreement", label: t("status.timeline.agreement"), state: app.submitted_at ? "done" : "waiting", note: date(app.submitted_at) },
    {
      key: "review",
      label: t("status.timeline.review"),
      state: app.status === "approved" ? "done" : app.status === "rejected" ? "failed" : app.status === "changes_requested" ? "attention" : "waiting",
      note: decided ? date(app.decided_at) : inReview ? t("status.timeline.reviewWaiting") : null,
    },
    { key: "access", label: t("status.timeline.access"), state: app.status === "approved" ? "done" : "waiting", note: app.status === "approved" ? t("status.timeline.accessReady") : t("status.timeline.accessAfter") },
  ];

  return (
    <section className="rounded-lg border border-line bg-surface p-5 md:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-h2 font-semibold">{t(`types.${type}.yourApplication`)}</h2>
          <p className="mt-1 text-body-sm text-secondary">
            {t("status.reference")} <span dir="ltr">{app.number}</span>
            {app.submitted_at ? ` · ${t("status.submittedOn", { date: date(app.submitted_at) ?? "" })}` : ""}
          </p>
        </div>
        <Badge tone={STATUS_TONE[app.status] ?? "neutral"}>{label(`status.states.${app.status}`, app.status)}</Badge>
      </div>

      {app.status === "approved" ? (
        <Alert tone="success" className="mt-4">
          <p className="font-semibold">{t("status.approvedTitle")}</p>
          <p>{t("status.approvedBody", { email: app.account.email ?? "" })}</p>
        </Alert>
      ) : app.status === "rejected" ? (
        <Alert tone="danger" className="mt-4">
          <p className="font-semibold">{t("status.rejectedTitle")}</p>
          {app.decision_reason ? <p className="whitespace-pre-wrap">{app.decision_reason}</p> : null}
          <p className="mt-1">{t("status.rejectedBody")}</p>
        </Alert>
      ) : app.status === "changes_requested" ? (
        <Alert tone="warning" className="mt-4">
          <p className="font-semibold">{t("status.changesTitle")}</p>
          {app.requested_changes?.note ? <p className="whitespace-pre-wrap">{app.requested_changes.note}</p> : null}
        </Alert>
      ) : app.status === "draft" ? (
        <Alert className="mt-4">{t("status.draftBody")}</Alert>
      ) : app.status === "withdrawn" ? (
        <Alert className="mt-4">{t("status.withdrawnBody")}</Alert>
      ) : null}

      <ol className="mt-5 flex flex-col gap-4">
        {timeline.map((row) => (
          <li key={row.key} className="flex items-start gap-3">
            {row.state === "done" ? (
              <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-success-text" />
            ) : row.state === "attention" ? (
              <AlertCircle className="mt-0.5 size-5 shrink-0 text-brand-text" />
            ) : row.state === "failed" ? (
              <XCircle className="mt-0.5 size-5 shrink-0 text-danger-text" />
            ) : (
              <Circle className="mt-0.5 size-5 shrink-0 text-muted" />
            )}
            <div className="min-w-0 flex-1">
              <p className="text-body font-medium">{row.label}</p>
              {row.note ? <p className={clsx("text-caption", row.state === "attention" ? "text-danger-text" : "text-secondary")}>{row.note}</p> : null}
            </div>
            {row.action}
          </li>
        ))}
      </ol>

      {app.documents.some((d) => d.uploads.length > 0) ? (
        <div className="mt-5 border-t border-line pt-4">
          <h3 className="mb-2 text-body font-semibold">{t("steps.documents")}</h3>
          <ul className="flex flex-col gap-2">
            {app.documents
              .filter((d) => d.uploads.length > 0)
              .map((d) => {
                const latest = d.uploads[0]!;
                return (
                  <li key={d.document_type_id} className="flex flex-wrap items-center gap-2 text-body-sm">
                    <span className="font-medium">{d.name}</span>
                    <Badge tone={documentTone(latest.status)}>{label(`documents.status.${latest.status}`, latest.status)}</Badge>
                    {latest.rejection_reason ? <span className="text-danger-text">{latest.rejection_reason}</span> : null}
                  </li>
                );
              })}
          </ul>
        </div>
      ) : null}

      {banner ? <Alert tone="danger" className="mt-4">{banner}</Alert> : null}

      <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-4">
        {app.editable ? <ButtonLink href={wizardHref}>{app.status === "draft" ? t("status.continue") : t("status.updateResubmit")}</ButtonLink> : null}
        {app.status === "rejected" || app.status === "withdrawn" ? <ButtonLink href={href(`/join/${type}`)}>{t("status.startNew")}</ButtonLink> : null}
        {app.editable ? (
          confirming ? (
            <span className="flex flex-wrap items-center gap-2">
              <span className="text-body-sm">{t("status.withdrawConfirm")}</span>
              <Button variant="dark" size="sm" onClick={withdraw} disabled={pending}>
                {pending ? <Spinner /> : null}
                {t("status.withdraw")}
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setConfirming(false)}>
                {t("common.cancel")}
              </Button>
            </span>
          ) : (
            <Button variant="outline" onClick={() => setConfirming(true)}>
              {t("status.withdraw")}
            </Button>
          )
        ) : null}
        {!app.editable && !inReview ? (
          <Button variant="ghost" onClick={forget}>
            {t("status.forget")}
          </Button>
        ) : null}
      </div>
    </section>
  );
}
