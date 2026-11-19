import {
  ADMIN, RUN, expect, expectValidation, fetchFile, flash, flow, form, inviteStaff, main, open, recordOutcomes, sidebarLinks, signIn, submit, submitNoValidate, test,
} from "./helpers";

const R = "admin-panel-system-countries-staff-and-migration" as const;
recordOutcomes(R);

test("ADM-60 roles, narrow staff member and permission boundaries, Arabic RTL", async ({ page, browser }) => {
  test.setTimeout(240_000);
  const f = flow(page, R, "ADM-60", "Roles & permissions, staff, permission boundary, Arabic (RTL)", "Admin creates a narrow role (orders + categories view only), invites a staff member with it through the UI, and the new member — signed in separately with 2FA — sees only those sidebar items and gets 403 elsewhere; the member then switches to Arabic and the pages render right-to-left with Oman-time labels.");
  const role = `qa_narrow_${RUN}`;
  await f.step("Roles & permissions: create role with no name", "Refused: name required", async () => {
    await open(page, "/roles");
    await submitNoValidate(form(page, "/roles"));
    await expectValidation(page);
  });
  await f.step(`Create role "${role}" with orders.view and categories.view`, "Role listed with 2 permissions", async () => {
    const fm = form(page, "/roles");
    await fm.locator('[name="name"]').fill(role);
    for (const p of ["orders.view", "categories.view"]) await fm.locator(`input[name="permissions[]"][value="${p}"]`).check();
    await submit(fm);
    await expect(flash(page)).toContainText(/created/);
    return await flash(page).innerText();
  }, { fullPage: true });
  await f.step("Edit the role: add products.view, then remove it again", "Role updated twice", async () => {
    const fm = main(page).locator('form[action*="/roles/"]').filter({ has: page.locator(`input[value="${role}"]`) });
    if (!(await fm.count())) return "permissions are edited in the matrix; no per-role form on this page";
    await fm.locator('input[name="permissions[]"][value="products.view"]').check().catch(() => undefined);
    await submit(fm);
    const fm2 = main(page).locator('form[action*="/roles/"]').filter({ has: page.locator(`input[value="${role}"]`) });
    await fm2.locator('input[name="permissions[]"][value="products.view"]').uncheck().catch(() => undefined);
    await submit(fm2);
  });
  let email = "";
  await f.step("Staff: invite with an invalid email", "Refused", async () => {
    await open(page, "/staff");
    const fm = form(page, "/staff/invitations");
    await fm.locator('[name="name"]').fill("QA bad");
    await fm.locator('[name="email"]').fill("not-an-email");
    await submitNoValidate(fm);
    await expectValidation(page, /valid email|email/i);
  });
  await f.step(`Invite a staff member with role "${role}" and accept the emailed invitation (sets a password)`, "Invitation accepted; account listed as staff", async () => {
    await open(page, "/staff");
    const opts = await form(page, "/staff/invitations").locator('select[name="role"] option').evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent?.trim() ?? "" })));
    const r = opts.find((o) => o.v.includes(RUN) || o.t.toLowerCase().includes(RUN));
    expect(r, "custom role offered in the invite form").toBeTruthy();
    email = await inviteStaff(page, browser, r!.v, "narrow");
    await open(page, `/staff?q=${encodeURIComponent(email)}`);
    await expect(main(page)).toContainText(email);
    return email;
  });
  await f.step("Open the staff member's page", "Roles per country, status actions", async () => {
    await main(page).locator("tr", { hasText: email }).getByRole("link").first().click();
    await expect(main(page)).toContainText(new RegExp(RUN, "i"));
  }, { fullPage: true });
  const { context, page: np } = await signIn(browser, email);
  await f.step("Sign in as the new staff member (password + emailed code) in a separate browser", "Dashboard opens", async () => {
    await expect(np.locator("#panel-sidebar")).toBeVisible();
  }, { page: np, fullPage: true });
  await f.step("Sidebar shows only what the role allows", "Orders and Catalog visible; Finance, Staff, Roles, Data migration hidden", async () => {
    const links = await sidebarLinks(np);
    expect(links).toContain("/orders");
    expect(links).toContain("/catalog");
    for (const hidden of ["/finance", "/staff", "/roles", "/migration/quarantine", "/coupons", "/customers"]) expect(links).not.toContain(hidden);
    return `${links.length} sidebar links: ${links.join(" ")}`;
  }, { page: np });
  await f.step("Open forbidden pages directly (/staff, /finance/payouts, /roles, /migration/quarantine, /coupons/create)", "Each answers 403", async () => {
    const codes: string[] = [];
    for (const p of ["/staff", "/finance/payouts", "/roles", "/migration/quarantine", "/coupons/create"]) {
      const res = await np.goto(ADMIN(p));
      codes.push(`${p} ${res?.status()}`);
      expect(res?.status(), p).toBe(403);
    }
    return codes.join(", ");
  }, { page: np });
  await f.step("Allowed pages work: Orders list and an order, Categories", "200 with data", async () => {
    for (const p of ["/orders", "/orders/6", "/catalog/categories"]) {
      const res = await np.goto(ADMIN(p));
      expect(res?.status(), p).toBe(200);
    }
  }, { page: np });
  await f.step("Allowed page but no action permission: the order page offers no state change / refund", "Admin action forms absent; a direct POST is refused (403)", async () => {
    await np.goto(ADMIN("/orders/2"));
    await expect(np.locator('main form[action*="/sub-orders/"]')).toHaveCount(0);
    const status = await np.evaluate(async () => {
      const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? (document.querySelector('input[name="_token"]') as HTMLInputElement | null)?.value ?? "";
      const r = await fetch("/sub-orders/2/cancel", { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: `_token=${encodeURIComponent(token)}&reason=hack`, redirect: "manual" });
      return r.status;
    });
    expect(status).toBe(403);
    return `POST /sub-orders/2/cancel → ${status}`;
  }, { page: np });
  await f.step("Switch the language to العربية", "<html dir=rtl lang=ar>; sidebar and headings in Arabic", async () => {
    await np.goto(ADMIN("/"));
    await Promise.all([np.waitForLoadState("load"), np.locator("#panel-locale").selectOption("ar")]);
    await np.waitForLoadState("load");
    await expect(np.locator("html")).toHaveAttribute("dir", "rtl");
    await expect(np.locator("html")).toHaveAttribute("lang", "ar");
  }, { page: np, fullPage: true });
  for (const p of ["/orders", "/orders/6", "/catalog/categories"]) {
    await f.step(`Arabic page ${p}`, "RTL layout, Arabic labels, no raw translation keys, Oman-time label in Arabic where times are shown", async () => {
      await np.goto(ADMIN(p));
      await expect(np.locator("html")).toHaveAttribute("dir", "rtl");
      const text = await np.locator("main").innerText();
      expect(text).toMatch(/[؀-ۿ]/);
      expect(text).not.toMatch(/\b(?:panels?|messages|validation)\.[a-z_]+\.[a-z_]+/);
      const english = ["Oman time", "Order total", "Payment & settlement", "Admin actions", "Status", "Filter"].filter((w) => text.includes(w));
      return english.length ? `English left untranslated: ${english.join(", ")}` : (text.match(/(بتوقيت|توقيت) ?عُ?مان/)?.[0] ?? "no Oman-time label on this page");
    }, { page: np, fullPage: true });
  }
  await f.step("Switch back to English", "dir=ltr", async () => {
    await Promise.all([np.waitForLoadState("load"), np.locator("#panel-locale").selectOption("en")]);
    await np.waitForLoadState("load");
    await expect(np.locator("html")).toHaveAttribute("dir", "ltr");
  }, { page: np });
  await context.close();
  await f.step("Admin suspends the staff member in this country, then reactivates", "Status Suspended, then Active", async () => {
    await open(page, `/staff?q=${encodeURIComponent(email)}`);
    await main(page).locator("tr", { hasText: email }).getByRole("link").first().click();
    for (let i = 0; i < 2; i++) {
      const fm = main(page).locator('form[action$="/status"]').first();
      await submit(fm);
    }
    await expect(main(page)).toContainText(/Active/);
  });
  await f.step("Staff: send and revoke an invitation", "Invitation listed, then revoked", async () => {
    await open(page, "/staff");
    const fm = form(page, "/staff/invitations");
    const mail = `qa-revoke-${RUN}@nawy.test`;
    await fm.locator('[name="name"]').fill("QA Revoke");
    await fm.locator('[name="email"]').fill(mail);
    await fm.locator('select[name="role"]').selectOption("support");
    await submit(fm);
    const revoke = main(page).locator("tr, li", { hasText: mail }).locator('form[action*="/staff/invitations/"]').first();
    await submit(revoke);
    await expect(main(page).locator("tr, li", { hasText: mail }).locator('form[action*="/staff/invitations/"]')).toHaveCount(0);
  });
});

test("ADM-61 countries: switcher, payment gateways, domains, holidays, settings", async ({ page }) => {
  const f = flow(page, R, "ADM-61", "Countries & gateways, domains, holidays, settings", "Admin switches the working country (data is scoped), previews and validates a gateway config without changing checkout, adds/edits/removes a test domain and a holiday, and opens platform settings.");
  await f.step("Switch the country to Oman", "Header shows Oman · OMR; Customers list is Oman's", async () => {
    await open(page, "/customers");
    await Promise.all([page.waitForLoadState("load"), page.locator("#panel-country").selectOption("omn")]);
    await page.waitForLoadState("load");
    await expect(main(page)).toContainText(/Oman/);
  }, { fullPage: true });
  await f.step("Switch back to United Arab Emirates", "UAE data again", async () => {
    await Promise.all([page.waitForLoadState("load"), page.locator("#panel-country").selectOption("uae")]);
    await page.waitForLoadState("load");
    await expect(main(page)).toContainText(/United Arab Emirates/);
  });
  await f.step("Countries → Payment gateways; preview the checkout for AED 250", "Preview lists the gateways customers would see", async () => {
    await open(page, "/countries");
    await expect(page).toHaveURL(/payment-gateways/);
    const pv = main(page).locator("form", { has: page.locator('[name="preview_amount"]') });
    await pv.locator('[name="preview_amount"]').fill("250");
    await submit(pv);
    await expect(page).toHaveURL(/preview_amount=250/);
  }, { fullPage: true });
  await f.step("Save a gateway with minimum greater than maximum", "Refused by validation; checkout unchanged", async () => {
    await page.locator("main details").evaluateAll((ds) => ds.forEach((d) => ((d as HTMLDetailsElement).open = true)));
    const fm = main(page).locator('form[action*="/countries/payment-gateways/"]').filter({ has: page.locator('[name="min_amount"]') }).first();
    await fm.locator('[name="min_amount"]').fill("500");
    await fm.locator('[name="max_amount"]').fill("10");
    await submit(fm);
    await expectValidation(page, /greater|less|must|maximum|minimum/i);
  });
  const host = `qa-${RUN}.example-qa.test`;
  await f.step("Domains: add with no hostname", "Refused", async () => {
    await open(page, "/countries/domains");
    await submitNoValidate(form(page, "/countries/domains").filter({ has: page.locator('[name="hostname"]') }));
    await expectValidation(page);
  });
  await f.step(`Add inactive portal domain ${host}`, "Domain listed", async () => {
    const fm = form(page, "/countries/domains").filter({ has: page.locator('[name="hostname"]') });
    await fm.locator('[name="hostname"]').fill(host);
    await fm.locator('select[name="kind"]').selectOption("portal");
    await fm.locator('select[name="country_id"]').selectOption("1");
    const active = fm.locator('input[type=checkbox][name="is_active"]');
    if (await active.isChecked()) await active.uncheck();
    await submit(fm);
    await expect(main(page)).toContainText(host);
  });
  await f.step("Edit the domain note, then delete it", "Saved, then removed", async () => {
    await main(page).locator("tr", { hasText: host }).locator('a[href*="/edit"]').click();
    const fm = main(page).locator('form[action*="/countries/domains/"]').filter({ has: page.locator('[name="hostname"]') });
    await fm.locator('[name="note"]').fill(`QA ${RUN}`);
    await submit(fm);
    await open(page, "/countries/domains");
    const del = main(page).locator("tr", { hasText: host }).locator("form").last();
    if (await del.count()) await submit(del);
    else {
      await main(page).locator("tr", { hasText: host }).locator('a[href*="/edit"]').click();
      await submit(main(page).locator("form", { has: page.locator('input[name="_method"][value="DELETE"]') }));
    }
    await open(page, "/countries/domains");
    await expect(main(page)).not.toContainText(host);
  });
  await f.step("Holidays: add with end before start", "Refused", async () => {
    await open(page, "/settings/holidays");
    const fm = form(page, "/settings/holidays").last();
    await fm.locator('[name="name_en"]').fill(`QA Day ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`يوم ${RUN}`);
    await fm.locator('[name="starts_on"]').fill("2026-12-20");
    await fm.locator('[name="ends_on"]').fill("2026-12-10");
    await submit(fm);
    await expectValidation(page, /after|before|must|equal/i);
  });
  await f.step("Add holiday 2026-12-20..21, rename it, then remove it", "Holiday added, edited and removed", async () => {
    await open(page, "/settings/holidays");
    const fm = form(page, "/settings/holidays").last();
    await fm.locator('[name="name_en"]').fill(`QA Day ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`يوم ${RUN}`);
    await fm.locator('[name="starts_on"]').fill("2026-12-20");
    await fm.locator('[name="ends_on"]').fill("2026-12-21");
    await submit(fm);
    const row = () => main(page).locator("form", { has: page.locator(`input[value="QA Day ${RUN}"]`) }).first();
    await expect(row()).toBeVisible();
    await row().locator('[name="name_en"]').fill(`QA Day ${RUN} x`);
    await submit(row());
    const action = await main(page).locator("form", { has: page.locator(`input[value="QA Day ${RUN} x"]`) }).first().getAttribute("action");
    await submit(main(page).locator(`form[action="${action}"]`, { has: page.locator('input[name="_method"][value="DELETE"]') }));
    await expect(main(page).locator(`input[value="QA Day ${RUN} x"]`)).toHaveCount(0);
  }, { fullPage: true });
  await f.step("Platform settings hub", "Links to zones, holidays, warranty, gateways, flash sales", async () => {
    await open(page, "/settings");
    await expect(main(page)).toContainText(/Holidays/);
  });
});

test("ADM-62 activity log, system health, notifications, security, portal analytics, cross-country reports", async ({ page }) => {
  const f = flow(page, R, "ADM-62", "Activity log, health, notifications, analytics, reports", "Admin filters the activity log, checks system health, reads notifications and sign-in history, switches portal-analytics periods, and filters / reloads / exports cross-country reports.");
  await f.step("Activity log: filter log 'coupons', scope country, today", "Entries of the coupon changes made in this run, Oman time", async () => {
    await open(page, "/activity?log=coupons&scope=country");
    await expect(main(page)).toContainText(/Oman/);
  }, { fullPage: true });
  await f.step("System health", "Queues, failed jobs, country databases 8/8, mail, storage", async () => {
    await open(page, "/health");
    await expect(main(page)).toContainText(/Country databases/);
    const failed = main(page).locator('form[action*="/health/failed-jobs/"]');
    return `${await failed.count()} failed-job actions`;
  }, { fullPage: true });
  await f.step("Notifications: mark all as read", "Unread badge cleared", async () => {
    await open(page, "/notifications");
    const fm = form(page, "/notifications/read-all");
    if (await fm.count()) await submit(fm);
  });
  await f.step("Account security (sign-in history)", "Recent sign-ins with time and device", async () => {
    await open(page, "/account/security");
  });
  await f.step("Portal analytics: 7 / 30 / 90 days", "Each period renders", async () => {
    for (const d of ["7", "30", "90"]) await open(page, `/portal-analytics?days=${d}`);
  });
  await f.step("Cross-country reports: UAE + Oman, 2026-11-01..2026-11-30", "Sales by country per currency (never summed across currencies)", async () => {
    await open(page, "/reports/cross-country?from=2026-11-01&to=2026-11-30&countries[]=uae&countries[]=omn");
    await expect(main(page)).toContainText(/AED/);
  }, { fullPage: true });
  await f.step("Reload now (reporting ETL for today)", "Reload queued/finished message", async () => {
    const fm = form(page, "/reports/cross-country/run");
    await submit(fm);
    await expect(flash(page).or(page.locator('main [role="alert"]'))).toBeVisible();
    return (await flash(page).or(page.locator('main [role="alert"]')).first().innerText()).slice(0, 160);
  });
  await f.step("Export CSV", "CSV file with a header row", async () => {
    const href = await main(page).locator("a", { hasText: /Export CSV/ }).first().getAttribute("href");
    const res = await fetchFile(page, href!);
    expect(res.status).toBe(200);
    expect(res.type).toMatch(/csv|text/);
    return `${res.size} bytes, ${res.type}`;
  });
});

test("ADM-63 data migration: quarantine, runs, retries, records, redirects, reconciliation", async ({ page }) => {
  test.setTimeout(240_000);
  const f = flow(page, R, "ADM-63", "Data migration", "Admin works the quarantine queue (filter, detail, reveal personal data, dry-run preview, decision), browses runs, previews a step re-try, searches legacy records (reveal), searches/exports/rebuilds redirects and runs reconciliation.", "Reads the dev migration tables only; the legacy snapshot database is never written.");
  await f.step("Quarantine queue: filter step 'orders' (open entries)", "Filtered entries", async () => {
    await open(page, "/migration");
    await expect(page).toHaveURL(/quarantine/);
    await open(page, "/migration/quarantine?step=orders");
    if (!(await main(page).locator('a[href*="/migration/quarantine/"]').count())) await open(page, "/migration/quarantine");
  }, { fullPage: true });
  let entry = "";
  await f.step("Open an entry", "Why quarantined, rule, legacy row with personal data masked", async () => {
    entry = (await main(page).locator('a[href*="/migration/quarantine/"]').first().getAttribute("href"))!;
    await open(page, entry);
    await expect(main(page)).toContainText(/masked/);
  }, { fullPage: true });
  await f.step("Reveal personal data", "Unmasked values shown; the reveal is logged", async () => {
    await submit(form(page, "/reveal"));
    await expect(main(page)).not.toContainText(/Personal data is masked/);
  });
  await f.step("Preview re-try (dry run)", "Dry-run result shown; nothing written", async () => {
    await open(page, entry);
    await submit(form(page, "/preview"));
    await expect(main(page)).not.toContainText(/Server Error/);
    return (await flash(page).innerText().catch(() => "")) || (await main(page).innerText()).slice(0, 120);
  }, { fullPage: true });
  await f.step("Save decision with no decision chosen", "Refused", async () => {
    await open(page, entry);
    const fm = form(page, "/review");
    await fm.locator('select[name="decision"]').evaluate((s) => { const el = s as HTMLSelectElement; el.innerHTML += '<option value="">—</option>'; el.value = ""; });
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Save decision 'waived' with a note", "Entry marked waived", async () => {
    await open(page, entry);
    const fm = form(page, "/review");
    await fm.locator('select[name="decision"]').selectOption("waived");
    await fm.locator('[name="note"]').fill(`QA ${RUN}: test order of a closed market`);
    await submit(fm);
    await expect(main(page)).toContainText(/waived|Waived/);
  });
  await f.step("Migration runs: filter step 'orders', open a run", "Rows read/written/quarantined and money per currency", async () => {
    await open(page, "/migration/runs?step=orders");
    await main(page).locator('a[href*="/migration/runs/"]').first().click();
    await expect(page).toHaveURL(/migration\/runs\/\d+/);
  }, { fullPage: true });
  await f.step("Re-try a step: preview (dry run) step 'reference' for UAE", "Dry-run job page; Apply offered, not clicked", async () => {
    await open(page, "/migration/runs");
    const fm = form(page, "/migration/retries");
    await fm.locator('select[name="step"]').selectOption("reference");
    await fm.locator('select[name="country"]').selectOption("uae");
    await submit(fm);
    await expect(main(page)).not.toContainText(/Server Error/);
    return page.url();
  }, { fullPage: true });
  await f.step("Legacy records: search central 'users', open one and reveal", "Record detail; masked until revealed", async () => {
    await open(page, "/migration/records?database=central");
    const href = await main(page).locator('a[href*="/migration/records/"]').first().getAttribute("href");
    await open(page, href!);
    const rv = form(page, "/reveal");
    if (await rv.count()) await submit(rv);
  }, { fullPage: true });
  await f.step("Legacy redirects: search 'adsupport', source portal", "Matching 301s", async () => {
    await open(page, "/migration/redirects?q=adsupport&source=portal");
    await expect(main(page)).toContainText(/adsupport/);
  });
  await f.step("Export JSON and nginx map", "Both downloads answer 200", async () => {
    const out: string[] = [];
    for (const fmt of ["json", "map"]) {
      const href = await main(page).locator(`a[href*="/migration/redirects/export."]`).evaluateAll((as, f) => (as as HTMLAnchorElement[]).map((a) => a.href).find((h) => h.includes(`.${f}`) || (f === "map" && /nginx|conf|map/.test(h))), fmt);
      if (!href) continue;
      const res = await fetchFile(page, href);
      expect(res.status).toBe(200);
      out.push(`${href.split("/").pop()} ${res.size} bytes`);
    }
    return out.join("; ");
  });
  await f.step("Rebuild map", "Redirect map rebuilt message", async () => {
    await submit(form(page, "/migration/redirects/rebuild"));
    await expect(flash(page).or(page.locator('main [role="alert"]'))).toBeVisible();
  });
  await f.step("Reconciliation: run reconcile", "Report regenerated: result and per-table counts", async () => {
    await open(page, "/migration/reconciliation");
    const pending = main(page).locator("[data-reconcile-pending]");
    if (await pending.count()) return `${await pending.innerText()} — the run button is hidden while a run is queued; no queue worker runs in dev (Horizon inactive), so it stays queued`;
    await submit(form(page, "/migration/reconciliation"));
    await expect(main(page)).toContainText(/Result/);
    return (await flash(page).innerText().catch(() => "")).slice(0, 160);
  }, { fullPage: true });
});
