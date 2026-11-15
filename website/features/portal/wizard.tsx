"use client";

import clsx from "clsx";
import { Check, CheckCircle2, Mail } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert, Skeleton, Spinner } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import {
  getApplication,
  myApplicationNumbers,
  requestResumeLink,
  saveApplicationStep,
  startApplication,
  submitApplication,
  TYPE_STEPS,
  type Application,
  type ApplicationType,
} from "@/lib/api/portal";
import { useHref, useMarket } from "@/lib/market-context";
import { usePortalLabel } from "./use-portal-t";
import { AgreementStep } from "./agreement-step";
import { DocumentsStep } from "./documents-step";
import { bannerError, fieldErrors } from "./errors";
import { buildBody, initialValues, stepFields, type Values } from "./step-fields";
import { StepForm } from "./step-form";

/** The wizard's steps: the API's steps, plus documents (when asked) and the agreement for travel agencies (P1). */
function wizardSteps(type: ApplicationType, app: Application | null): string[] {
  const steps = app ? app.steps.map((s) => s.key) : [...TYPE_STEPS[type]];
  if (!steps.includes("agreement")) {
    if (!steps.includes("documents") && app && app.documents.some((d) => d.requirement !== "not_applicable")) steps.push("documents");
    steps.push("agreement");
  }
  return steps;
}

function isDone(app: Application | null, step: string): boolean {
  if (!app) return false;
  const known = app.steps.find((s) => s.key === step);
  // The API marks `documents` complete when the step is saved with nothing missing (round 2);
  // types without an API documents step (travel agencies) are checked at submission.
  if (step === "documents") return known ? known.completed : app.documents.every((d) => !d.missing);
  if (step === "agreement") return app.submitted_at !== null && app.status !== "changes_requested";
  return known?.completed ?? false;
}

function firstOpenStep(type: ApplicationType, app: Application | null): string {
  if (!app) return "account";
  const changes = app.requested_changes?.steps?.find((s) => wizardSteps(type, app).includes(s));
  if (app.status === "changes_requested" && changes) return changes;
  return wizardSteps(type, app).find((s) => !isDone(app, s)) ?? "agreement";
}

/**
 * Partner registration wizard for one application type (Figma Seller reg
 * 93:997 / 73:384 / 93:1128 / 73:594 / 73:724, Travel reg 93:1654 / 73:1017,
 * Creator 73:846, Shipping 73:1145). The account step opens the draft; the
 * proxy keeps its access token, so leaving and coming back resumes it.
 */
export function RegistrationWizard({ type, number, role }: { type: ApplicationType; number?: string; role?: string }) {
  const t = useTranslations("portal");
  const market = useMarket();
  const href = useHref();
  const label = usePortalLabel();
  const [app, setApp] = useState<Application | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [step, setStep] = useState("account");
  const [pending, setPending] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);

  // Resume: the application in the URL, else this browser's open draft of this type.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        let found: Application | null = null;
        if (number) {
          found = (await getApplication(market.slug, number)).data;
        } else {
          const numbers = (await myApplicationNumbers(market.slug)).data;
          for (const n of numbers) {
            try {
              const candidate = (await getApplication(market.slug, n)).data;
              if (candidate.type === type && candidate.editable) {
                found = candidate;
                break;
              }
            } catch {
              // Forgotten by the proxy when no longer reachable.
            }
          }
        }
        if (cancelled) return;
        if (found && found.type !== type) {
          window.location.replace(`${href(`/join/${found.type}`)}?number=${encodeURIComponent(found.number)}`);
          return;
        }
        setApp(found);
        setStep(firstOpenStep(type, found));
      } catch (error) {
        if (!cancelled) setLoadError(error instanceof ApiError && error.status === 404 ? t("wizard.notFound") : t("common.error"));
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- load once per application
  }, [market.slug, number, type]);

  const steps = useMemo(() => wizardSteps(type, app), [type, app]);
  const index = Math.max(0, steps.indexOf(step));
  const go = (next: string) => {
    setErrors({});
    setBanner(null);
    setStep(next);
    window.scrollTo({ top: 0, behavior: "smooth" });
  };
  const goNext = (updated: Application) => {
    const list = wizardSteps(type, updated);
    go(list[list.indexOf(step) + 1] ?? "agreement");
  };

  const fail = (error: unknown) => {
    setErrors(fieldErrors(error));
    if (error instanceof ApiError && error.code === "documents_missing") setBanner(t("documents.missing"));
    else setBanner(bannerError(error, t("common.error")));
  };

  const saveData = async (values: Values) => {
    const fields = stepFields(type, step, !app);
    setPending(true);
    setErrors({});
    setBanner(null);
    try {
      const body = buildBody(fields, values);
      const updated = !app
        ? (await startApplication(market.slug, type, body as Record<string, unknown>)).data
        : (await saveApplicationStep(market.slug, app.number, step, body)).data;
      setApp(updated);
      goNext(updated);
    } catch (error) {
      fail(error);
    } finally {
      setPending(false);
    }
  };

  const continueDocuments = async () => {
    if (!app) return;
    setPending(true);
    setBanner(null);
    try {
      // Travel agencies have no documents step in the API: their documents are checked at submission.
      const updated = app.steps.some((s) => s.key === "documents") ? (await saveApplicationStep(market.slug, app.number, "documents", {})).data : app;
      if (!app.steps.some((s) => s.key === "documents") && updated.documents.some((d) => d.missing)) {
        setBanner(t("documents.missing"));
        return;
      }
      setApp(updated);
      goNext(updated);
    } catch (error) {
      fail(error);
    } finally {
      setPending(false);
    }
  };

  const submit = async (fullName: string) => {
    if (!app) return;
    setPending(true);
    setErrors({});
    setBanner(null);
    try {
      setApp((await submitApplication(market.slug, app.number, fullName)).data);
      window.scrollTo({ top: 0, behavior: "smooth" });
    } catch (error) {
      fail(error);
    } finally {
      setPending(false);
    }
  };

  if (loading) return <Skeleton className="h-[480px] w-full" />;
  if (loadError) {
    return (
      <Alert tone="danger">
        {loadError}{" "}
        <a href={href(`/join/${type}`)} className="font-semibold underline">
          {t("wizard.startNew")}
        </a>
      </Alert>
    );
  }

  if (app && !app.editable) return <SubmittedView app={app} />;

  const title = t(`types.${type}.register`);
  const saveLabel = t("wizard.saveContinue");

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-h1 font-semibold">{title}</h1>
        {app ? <p className="mt-1 text-body-sm text-secondary">{t("wizard.draftSaved", { number: app.number })}</p> : <p className="mt-1 text-body-sm text-secondary">{t(`types.${type}.intro`)}</p>}
      </div>

      <ol className="scrollbar-none flex gap-4 overflow-x-auto" aria-label={t("wizard.progress")}>
        {steps.map((key, i) => {
          const done = isDone(app, key);
          const current = key === step;
          const reachable = !!app && (done || i <= steps.findIndex((s) => !isDone(app, s)) || app.status === "changes_requested");
          return (
            <li key={key} className="shrink-0">
              <button
                type="button"
                disabled={!reachable || current}
                onClick={() => go(key)}
                aria-current={current ? "step" : undefined}
                className="flex items-center gap-2 text-start disabled:cursor-default"
              >
                <span className={clsx("flex size-7 items-center justify-center rounded-full border text-caption font-semibold", current ? "border-brand bg-brand" : done ? "border-success-text bg-success-subtle text-success-text" : "border-line-strong text-secondary")}>
                  {done && !current ? <Check className="size-4" /> : i + 1}
                </span>
                <span>
                  <span className={clsx("block text-body-sm", current ? "font-semibold" : "text-secondary")}>{label(`steps.${key}`, key)}</span>
                  <span className="block text-caption text-muted">{current ? t("wizard.inProgress") : done ? t("wizard.completed") : t("wizard.notStarted")}</span>
                </span>
              </button>
            </li>
          );
        })}
      </ol>

      {app?.status === "changes_requested" ? (
        <Alert tone="warning">
          <p className="font-semibold">{t("status.changesTitle")}</p>
          {app.requested_changes?.note ? <p className="mt-1 whitespace-pre-wrap">{app.requested_changes.note}</p> : null}
        </Alert>
      ) : null}

      {step === "documents" && app ? (
        <DocumentsStep app={app} onChange={setApp} onContinue={continueDocuments} onBack={() => go(steps[index - 1] ?? "account")} pending={pending} error={banner} submitLabel={saveLabel} />
      ) : step === "agreement" && app ? (
        <AgreementStep app={app} onSubmit={submit} onBack={() => go(steps[index - 1] ?? "account")} pending={pending} errors={errors} error={banner} />
      ) : (
        <>
          <StepForm
            key={`${step}-${app?.number ?? "new"}`}
            title={label(`stepTitles.${step}`, step)}
            fields={stepFields(type, step, !app)}
            initial={initialValues(stepFields(type, step, !app), app, step, step === "profile" && !app?.data?.profile && (role === "influencer" || role === "affiliate") ? { job_types: [role] } : {})}
            errors={errors}
            pending={pending}
            submitLabel={saveLabel}
            onSubmit={saveData}
            onBack={index > 0 ? () => go(steps[index - 1]) : undefined}
          />
          {banner ? <Alert tone="danger">{banner}</Alert> : null}
          {!app ? <ResumeLinkForm type={type} /> : null}
        </>
      )}
    </div>
  );
}

/** "Already started? Email me a link" — never says whether an application exists (P3). */
function ResumeLinkForm({ type }: { type: ApplicationType }) {
  const t = useTranslations("portal");
  const market = useMarket();
  const [email, setEmail] = useState("");
  const [state, setState] = useState<"idle" | "pending" | "sent" | "error">("idle");

  const send = async (event: FormEvent) => {
    event.preventDefault();
    setState("pending");
    try {
      await requestResumeLink(market.slug, email.trim(), type);
      setState("sent");
    } catch {
      setState("error");
    }
  };

  return (
    <details className="rounded-lg border border-line bg-surface p-4">
      <summary className="cursor-pointer text-body font-medium">{t("resume.question")}</summary>
      {state === "sent" ? (
        <Alert tone="success" className="mt-3">
          {t("resume.sent")}
        </Alert>
      ) : (
        <form onSubmit={send} className="mt-3 flex flex-wrap items-end gap-3">
          <Field label={t("fields.email")} className="min-w-64 flex-1">
            <Input type="email" dir="ltr" required value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
          </Field>
          <Button type="submit" variant="outline" disabled={state === "pending"}>
            {state === "pending" ? <Spinner /> : <Mail className="size-4" />}
            {t("resume.send")}
          </Button>
          {state === "error" ? <p className="w-full text-body-sm text-danger-text">{t("common.error")}</p> : null}
        </form>
      )}
    </details>
  );
}

/** Submitted / in review (Figma Seller reg — success 93:1258, Travel reg — success 93:1750). */
function SubmittedView({ app }: { app: Application }) {
  const t = useTranslations("portal");
  const href = useHref();
  return (
    <div className="mx-auto flex max-w-xl flex-col items-center gap-4 rounded-lg border border-line bg-surface p-8 text-center">
      <CheckCircle2 className="size-14 text-success-text" />
      <h1 className="text-h2 font-semibold">{t("success.title")}</h1>
      <p className="text-body text-secondary">{t("success.body", { email: app.account.email ?? "" })}</p>
      <p className="text-body-sm">
        {t("success.reference")} <span className="font-semibold" dir="ltr">{app.number}</span>
      </p>
      <ButtonLink href={`${href("/join/status")}?number=${encodeURIComponent(app.number)}`}>{t("success.viewStatus")}</ButtonLink>
    </div>
  );
}
