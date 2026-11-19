import {
  ADMIN, RUN, alertBox, panelPage, ensureAccountManager, expectValidation, expect, fieldErrors, firstDetailHref, flash, flow, form, main, open, recordOutcomes,
  sidebarLinks, submit, submitNoValidate, test,
} from "./helpers";
import { panelUrl } from "../../../support/accounts";

const R = "admin-panel-catalog-and-vendors" as const;
recordOutcomes(R);

test("ADM-01 dashboard and full sidebar", async ({ page }) => {
  const f = flow(page, R, "ADM-01", "Dashboard and admin sidebar", "The dashboard loads with KPIs, the Oman-time label and every admin sidebar group; each of the 65 sidebar links opens without a server error.");
  await f.step("Open the admin dashboard", "Dashboard renders with KPI cards and the sidebar", async () => {
    await open(page, "/");
    await expect(page.locator("#panel-sidebar")).toBeVisible();
    await expect(main(page)).toContainText(/Oman time/);
  }, { fullPage: true });
  let links: string[] = [];
  await f.step("Read every sidebar link", "65 links across 8 groups (Overview, Commerce, Growth, Verticals, Operations, Content, Platform, Data migration); none is a 'Soon' placeholder", async () => {
    links = [...new Set(await sidebarLinks(page))];
    for (const g of ["Overview", "Commerce", "Growth", "Verticals", "Operations", "Content", "Platform", "Data migration"]) {
      await expect(page.locator("#panel-sidebar")).toContainText(g);
    }
    expect(links.length).toBe(65);
    await expect(page.locator("#panel-sidebar")).not.toContainText(/\bSoon\b/);
    return `${links.length} sidebar links`;
  });
  await f.step("Open each sidebar link in turn", "Every page answers 200 with no server-error text or raw translation keys", async () => {
    const bad: string[] = [];
    for (const l of links) {
      const res = await page.goto(ADMIN(l), { waitUntil: "load" });
      const text = await page.locator("body").innerText();
      if (res?.status() !== 200 || /Server Error|Whoops|SQLSTATE|ErrorException/.test(text)) bad.push(`${l} (${res?.status()})`);
    }
    expect(bad).toEqual([]);
    return `${links.length} pages opened, 0 errors`;
  });
});

test("ADM-02 categories: list, filter, create with validation, edit, attribute", async ({ page }) => {
  const f = flow(page, R, "ADM-02", "Catalog categories", "Admin manages the category tree: search, create (with validation errors first), edit and add an attribute.");
  const slug = `qa-cat-${RUN}`;
  await f.step("Open Catalog → Categories", "Category tree with bilingual names, commission and status", async () => {
    await open(page, "/catalog");
    await expect(page).toHaveURL(/catalog\/categories/);
    await expect(main(page)).toContainText("Electronics");
  });
  await f.step("Search 'mobiles' and filter Active", "Only matching categories are listed", async () => {
    const fm = form(page, "/catalog/categories").filter({ has: page.locator('input[name="q"]') });
    await fm.locator('input[name="q"]').fill("mobiles");
    await fm.locator('select[name="status"]').selectOption("active");
    await submit(fm);
    await expect(main(page)).toContainText(/Mobiles/);
    await expect(main(page)).not.toContainText("Wearables");
  });
  await f.step("Open New category and submit it empty", "Validation errors on the required names and slug; nothing saved", async () => {
    await open(page, "/catalog/categories/create");
    await submitNoValidate(form(page, "/catalog/categories"));
    await expectValidation(page, /name \(English\) field is required|required/i);
  });
  await f.step(`Create category ${slug} (EN/AR names, commission 7.5%, return window 14)`, "Saved; success message and the category appears in the tree", async () => {
    const fm = form(page, "/catalog/categories");
    await fm.locator('[name="name_en"]').fill(`QA Category ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`فئة اختبار ${RUN}`);
    await fm.locator('[name="slug"]').fill(slug);
    await fm.locator('[name="commission_rate"]').fill("7.5");
    await fm.locator('[name="return_window_days"]').fill("14");
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required|already been taken/);
    await open(page, `/catalog/categories?q=${slug}`);
    await expect(main(page)).toContainText(`QA Category ${RUN}`);
  });
  await f.step("Open the new category and add a 'colour' select attribute", "Attribute is added to the category", async () => {
    const href = await page.locator("main a", { hasText: `QA Category ${RUN}` }).first().getAttribute("href");
    await open(page, href!.replace(/\/edit$/, ""));
    const fm = form(page, "/attributes");
    await fm.locator('[name="key"]').fill(`colour_${RUN}`);
    await fm.locator('[name="label_en"]').fill("Colour");
    await fm.locator('[name="label_ar"]').fill("اللون");
    const type = fm.locator('[name="type"]');
    if (await type.locator('option[value="select"]').count()) await type.selectOption("select");
    await fm.locator('[name="options"]').fill("Red, Blue");
    await submit(fm);
    await expect(main(page)).toContainText(`colour_${RUN}`);
  });
  await f.step("Edit the category: rename and set inactive", "Changes saved and shown in the list as Inactive", async () => {
    const path = new URL(page.url()).pathname.replace(/\/$/, "");
    await open(page, `${path}/edit`);
    const fm = form(page, path);
    await fm.locator('[name="name_en"]').fill(`QA Category ${RUN} edited`);
    const active = fm.locator('input[type="checkbox"][name="is_active"]');
    if (await active.isChecked()) await active.uncheck();
    await submit(fm);
    await open(page, `/catalog/categories?q=${slug}`);
    await expect(main(page)).toContainText(`QA Category ${RUN} edited`);
    await expect(main(page)).toContainText(/Inactive/);
  });
});

test("ADM-03 brands: filter, create with validation, edit", async ({ page }) => {
  const f = flow(page, R, "ADM-03", "Catalog brands", "Admin adds and edits a brand; duplicate/empty input is refused.");
  await f.step("Open Brands", "Brand list with search and status filter", async () => {
    await open(page, "/catalog/brands");
    await expect(form(page, "/catalog/brands").first()).toBeVisible();
  });
  await f.step("Submit Add brand empty", "Required-field errors for name and slug", async () => {
    await submitNoValidate(form(page, "/catalog/brands").filter({ has: page.locator('input[name="slug"]') }));
    await expectValidation(page);
  });
  await f.step(`Add brand "QA Brand ${RUN}"`, "Brand saved and listed", async () => {
    const fm = form(page, "/catalog/brands").filter({ has: page.locator('input[name="slug"]') });
    await fm.locator('[name="name"]').fill(`QA Brand ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`علامة ${RUN}`);
    await fm.locator('[name="slug"]').fill(`qa-brand-${RUN}`);
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required|already been taken/);
    await open(page, `/catalog/brands?q=QA+Brand+${RUN}`);
    await expect(main(page)).toContainText(`QA Brand ${RUN}`);
  });
  await f.step("Add the same slug again", "Refused: slug already taken", async () => {
    const fm = form(page, "/catalog/brands").filter({ has: page.locator('input[name="slug"]') });
    await fm.locator('[name="name"]').fill(`QA Brand dup ${RUN}`);
    await fm.locator('[name="slug"]').fill(`qa-brand-${RUN}`);
    await submit(fm);
    await expect(main(page)).toContainText(/already been taken|taken/i);
  });
  await f.step("Edit the brand name and save", "Saved with the new name", async () => {
    await open(page, `/catalog/brands?q=QA+Brand+${RUN}`);
    const edit = await firstDetailHref(page, /\/catalog\/brands\/\d+\/edit$/);
    expect(edit).toBeTruthy();
    await open(page, edit!);
    const fm = main(page).locator("form").filter({ has: page.locator('input[name="slug"]') });
    await fm.locator('[name="name"]').fill(`QA Brand ${RUN} v2`);
    await submit(fm);
    await open(page, `/catalog/brands?q=${RUN}`);
    await expect(main(page)).toContainText(`QA Brand ${RUN} v2`);
  });
});

test("ADM-04 products: filter, detail, edit validation, variant", async ({ page }) => {
  const f = flow(page, R, "ADM-04", "Catalog products", "Admin finds a product, opens it, sees a validation error on edit and saves a harmless change (model number) and a variant field.");
  await f.step("Open Products and filter by 'Nova'", "Matching products listed", async () => {
    await open(page, "/catalog/products?q=Nova");
    await expect(main(page)).toContainText(/Nova/);
  });
  let detail = "";
  await f.step("Open a product (SoundMax, id 1)", "Product detail with variants, offers and status", async () => {
    await open(page, "/catalog/products/1");
    detail = page.url();
    await expect(main(page).locator('form[action*="/catalog/variants/"]').first()).toBeVisible();
  }, { fullPage: true });
  await f.step("Edit: clear the English title and save", "Validation error; product unchanged", async () => {
    await open(page, "/catalog/products/1/edit");
    const fm = form(page, "/catalog/products/1");
    await fm.locator('[name="title_en"]').fill("");
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step(`Edit: set model number QA-${RUN}`, "Saved; the detail page shows the model number", async () => {
    await open(page, "/catalog/products/1/edit");
    const fm = form(page, "/catalog/products/1");
    await fm.locator('[name="model_number"]').fill(`QA-${RUN}`);
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required|already been taken/);
    await open(page, "/catalog/products/1/edit");
    await expect(form(page, "/catalog/products/1").locator('[name="model_number"]')).toHaveValue(`QA-${RUN}`);
  });
  await f.step("Save a variant's weight (grams)", "Variant saved", async () => {
    await open(page, detail);
    const fm = main(page).locator('form[action*="/catalog/variants/"]').first();
    await fm.locator('[name="weight_grams"]').fill("250");
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required|already been taken/);
    await expect(main(page).locator('form[action*="/catalog/variants/"]').first().locator('[name="weight_grams"]')).toHaveValue("250");
  });
});

test("ADM-05 warranty plans", async ({ page }) => {
  const f = flow(page, R, "ADM-05", "Warranty plans", "Admin adds an extended-warranty plan (validation first) and edits it.");
  await f.step("Open Warranty plans", "Plan list and Add plan form", async () => {
    await open(page, "/catalog/warranty-plans");
  });
  await f.step("Submit Add plan empty", "Required errors (parent category, name, duration, price)", async () => {
    await submitNoValidate(form(page, "/catalog/warranty-plans").last());
    await expectValidation(page);
  });
  await f.step(`Add plan "QA 24m ${RUN}" (24 months, 49.00)`, "Plan saved and listed", async () => {
    const fm = form(page, "/catalog/warranty-plans").last();
    await fm.locator('select[name="category_id"]').selectOption({ index: 1 });
    await fm.locator('[name="name_en"]').fill(`QA 24m ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`ضمان ${RUN}`);
    await fm.locator('[name="duration_months"]').fill("24");
    await fm.locator('[name="price"]').fill("49");
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required|already been taken/);
    await expect(page.locator(`main input[value="QA 24m ${RUN}"]`)).toHaveCount(1);
  });
  await f.step("Edit the new plan price to 59.00", "Saved", async () => {
    const fm = main(page).locator("form").filter({ has: page.locator(`input[value="QA 24m ${RUN}"]`) });
    await fm.locator('[name="price"]').fill("59");
    await submit(fm);
    const again = main(page).locator("form").filter({ has: page.locator(`input[value="QA 24m ${RUN}"]`) });
    await expect(again.locator('[name="price"]')).toHaveValue(/59/);
  });
});

test("ADM-06 product requests: seller asks, admin approves and rejects", async ({ page, browser }) => {
  const f = flow(page, R, "ADM-06", "Product requests", "A seller asks for two catalogue products; admin reviews one (approve) and refuses the other (reject with reason).", "Admin session + an own e2e seller (E2E Store) session to raise the requests.");
  const { context: seller, page: sp } = await panelPage(browser, "partner");
  for (const n of [1, 2]) {
    await f.step(`Seller sends product request #${n} "QA Req ${RUN}-${n}"`, "Request saved as Pending in Seller Center", async () => {
      await sp.goto(panelUrl("partner", "product-requests"));
      const fm = sp.locator(`main form[action$="/product-requests"]`);
      await fm.locator('[name="title_en"]').fill(`QA Req ${RUN}-${n}`);
      await fm.locator('[name="brand_name"]').fill("QA Brand");
      await fm.locator('[name="gtin"]').fill(`62900000${String(Date.now()).slice(-5)}`);
      await fm.locator('[name="description"]').fill("Requested by the admin QA run");
      await submit(fm);
      await expect(sp.locator("main")).toContainText(`QA Req ${RUN}-${n}`);
    }, { page: sp });
  }
  await seller.close();
  await f.step("Admin opens Product requests (Pending)", "Both requests are listed", async () => {
    await open(page, "/product-requests");
    await expect(main(page)).toContainText(`QA Req ${RUN}-1`);
    await expect(main(page)).toContainText(`QA Req ${RUN}-2`);
  });
  await f.step("Open request #1, fill the Arabic name and approve (product stays a draft)", "Status Approved; a draft product is created", async () => {
    await main(page).locator("tr", { hasText: `QA Req ${RUN}-1` }).getByRole("link").first().click();
    await expect(page).toHaveURL(/product-requests\/\d+/);
    const fm = form(page, "/approve");
    await submitNoValidate(fm);
    await expectValidation(page);
    const fm2 = form(page, "/approve");
    const ar = fm2.locator('[name$="title_ar"], [name$="name_ar"]').first();
    await ar.fill(`طلب ${RUN}`);
    await submit(fm2);
    await expect(main(page)).toContainText(/Approved/);
  }, { fullPage: true });
  await f.step("Open request #2 and reject without a reason", "Validation error: reason required", async () => {
    await open(page, "/product-requests");
    await main(page).locator("tr", { hasText: `QA Req ${RUN}-2` }).getByRole("link").first().click();
    const fm = form(page, "/reject");
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Reject request #2 with a reason", "Status Rejected; the reason is shown", async () => {
    const fm = form(page, "/reject");
    const reason = fm.locator("textarea, input[type=text]").first();
    await reason.fill("Duplicate of an existing product");
    await submit(fm);
    await expect(main(page)).toContainText(/Rejected/);
  });
});

test("ADM-07 profile change requests: approve and reject", async ({ page, browser }) => {
  const f = flow(page, R, "ADM-07", "Profile change requests", "A seller asks to change locked store details; an unchanged value is refused, admin approves an address change and rejects a legal-details change.", "Admin session + an own e2e seller (E2E Store) session. Store name is never changed so other suites are unaffected.");
  const { context: seller, page: sp } = await panelPage(browser, "partner");
  const ask = async (section: string, values: Record<string, string>, note: string) => {
    await sp.goto(panelUrl("partner", "change-requests"));
    await sp.locator("main details").evaluateAll((ds) => ds.forEach((d) => ((d as HTMLDetailsElement).open = true)));
    const fm = sp.locator(`main form[action$="/change-requests"]`).filter({ has: sp.locator(`input[name="section"][value="${section}"]`) }).first();
    for (const [field, value] of Object.entries(values)) await fm.locator(`[name="values[${field}]"]`).fill(value);
    await fm.locator('[name="note"]').fill(note);
    await submit(fm);
  };
  await f.step("Seller asks to 'change' the store name to the same value", "Refused: 'Nothing changed in this section.'", async () => {
    await ask("store_name", { store_name: "E2E Store" }, `QA ${RUN} request 0`);
    await expect(sp.locator("main")).toContainText(/Nothing changed/);
  }, { page: sp });
  await f.step(`Seller asks to change the business address (street, area, city "QA City ${RUN}")`, "Request sent and listed as pending", async () => {
    await ask("address", { street: "QA Street 1", area: "Al Barsha", city: `QA City ${RUN}` }, `QA ${RUN} request 1`);
    await expect(sp.locator("main")).not.toContainText(/Nothing changed/);
    await expect(sp.locator("main")).toContainText(/Pending|pending/);
  }, { page: sp });
  await f.step(`Seller asks to change legal details (legal name "QA Legal ${RUN}", licence, tax ID)`, "Request sent and listed as pending", async () => {
    await ask("legal_details", { legal_name: `QA Legal ${RUN}`, cr_number: "CN-123456", tax_id: "100000000000003" }, `QA ${RUN} request 2`);
    await expect(sp.locator("main")).toContainText(/Pending|pending/);
  }, { page: sp });
  await seller.close();
  await f.step("Admin opens Profile change requests (Pending)", "Both pending requests listed for E2E Store", async () => {
    await open(page, "/change-requests");
    await expect(main(page)).toContainText(/E2E Store/);
  });
  const openRequest = async (marker: string, action: string) => {
    await open(page, "/change-requests");
    const hrefs = await page.locator("main a[href*='/change-requests/']").evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
    for (const href of hrefs) {
      await open(page, href);
      if ((await main(page).innerText()).includes(marker) && (await form(page, action).count())) return;
    }
    throw new Error(`request "${marker}" not found`);
  };
  await f.step("Open the address request and approve it", "Approved; old and new values shown", async () => {
    await openRequest(`QA ${RUN} request 1`, "/approve");
    await submit(form(page, "/approve"));
    await expect(main(page)).toContainText(/Approved/);
  }, { fullPage: true });
  await f.step("Open the legal-details request and reject it with a reason", "Rejected; reason recorded", async () => {
    await openRequest(`QA ${RUN} request 2`, "/reject");
    const fm = form(page, "/reject");
    await fm.locator("textarea, input:not([type=hidden])").first().fill("Name does not match the trade licence");
    await submit(fm);
    await expect(main(page)).toContainText(/Rejected/);
  });
});

test("ADM-08 reviews & Q&A moderation", async ({ page }) => {
  const f = flow(page, R, "ADM-08", "Reviews & Q&A moderation", "Admin moderates customer reviews (publish, reject with reason) and sees the questions/answers tabs.");
  await f.step("Open Reviews & Q&A (To moderate)", "Reviews tab with counts; Oman time label", async () => {
    await open(page, "/reviews");
    await expect(main(page)).toContainText(/Reviews \(\d+\)/);
    await expect(main(page)).toContainText(/Oman time/);
  });
  await f.step("Publish the first pending review", "Review leaves the queue / shows Published", async () => {
    const before = await form(page, "/reviews/reviews/").count();
    const fm = main(page).locator('form[action*="/reviews/reviews/"]').filter({ has: page.getByRole("button", { name: "Publish" }) }).first();
    const action = await fm.getAttribute("action");
    await submit(fm, "Publish");
    await expect(main(page).locator(`form[action="${action}"]`)).toHaveCount(0);
    return `queue had ${before} forms; ${action} published`;
  });
  await f.step("Reject the next pending review with a reason", "Review rejected", async () => {
    const fm = main(page).locator('form[action*="/reviews/reviews/"]').filter({ has: page.getByRole("button", { name: "Reject" }) }).first();
    const action = await fm.getAttribute("action");
    await fm.locator('input:not([type=hidden]), textarea').first().fill("Off-topic");
    await submit(fm, "Reject");
    await expect(main(page).locator(`form[action="${action}"]`)).toHaveCount(0);
  });
  for (const tab of ["Published", "Rejected"]) {
    await f.step(`Filter status ${tab}`, `${tab} reviews listed`, async () => {
      await main(page).getByRole("link", { name: tab, exact: true }).first().click();
      await expect(page).toHaveURL(new RegExp(tab.toLowerCase()));
    });
  }
  await f.step("Open the Questions tab", "Questions waiting for moderation listed", async () => {
    await open(page, "/reviews");
    await main(page).getByRole("link", { name: /Questions/ }).first().click();
    await expect(main(page)).toContainText(/Question|question/);
  });
});

test("ADM-09 vendors: filter, detail, account manager, acquirer, status", async ({ page, browser }) => {
  const f = flow(page, R, "ADM-09", "Vendors and account managers", "Admin filters vendors, opens one, assigns an account manager and an acquirer (acquired-by field), and changes a test vendor's status with a reason.", "Uses TechZone Electronics (V-16) and Suspended Test Store (V-17); the e2e vendors are not touched.");
  await f.step("Open Users & vendors", "Redirects to the Vendors list", async () => {
    await open(page, "/users");
    await expect(page).toHaveURL(/\/vendors/);
    await expect(main(page)).toContainText("E2E Store");
  });
  await f.step("Filter status Suspended", "Only Suspended Test Store", async () => {
    await open(page, "/vendors?status=suspended");
    await expect(main(page)).toContainText("Suspended Test Store");
    await expect(main(page)).not.toContainText("SoundMax Official");
  });
  await f.step("Open TechZone Electronics (V-16)", "Profile, sales totals, account manager, acquisition and status panels", async () => {
    await open(page, "/vendors/16");
    await expect(main(page)).toContainText("TechZone");
  }, { fullPage: true });
  await f.step("Make sure an account manager exists (Staff → invite with role Account manager, accept the emailed link)", "At least one active account manager", async () => {
    const who = await ensureAccountManager(page, browser);
    return `account manager: ${who}`;
  });
  await f.step("Assign the account manager to V-16", "Saved; account manager shown", async () => {
    await open(page, "/vendors/16");
    const fm = form(page, "/vendors/16/account-manager");
    const sel = fm.locator('select[name="account_manager_id"]');
    const opts = await sel.locator("option").evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent?.trim() })));
    const pick = opts.find((o) => o.v);
    await sel.selectOption(pick!.v);
    await submit(fm);
    await expect(form(page, "/vendors/16/account-manager").locator('select[name="account_manager_id"]')).toHaveValue(pick!.v);
    return `account manager: ${pick!.t}`;
  });
  await f.step("Set acquirer (Acquired by) and acquisition date", "Saved; 'Acquired by' shows the staff member", async () => {
    const fm = form(page, "/vendors/16/acquirer");
    const sel = fm.locator('select[name="acquired_by_user_id"]');
    await sel.selectOption({ label: "E2E Admin" });
    await fm.locator('[name="acquired_at"]').fill("2026-11-01");
    await submit(fm);
    await expect(form(page, "/vendors/16/acquirer").locator('select[name="acquired_by_user_id"] option:checked')).toHaveText(/E2E Admin/);
  });
  await f.step("Change status of V-17 without a reason", "Validation error: reason required", async () => {
    await open(page, "/vendors/17");
    const fm = form(page, "/vendors/17/status");
    await fm.locator('select[name="status"]').selectOption("on_hold").catch(async () => fm.locator('select[name="status"]').selectOption({ index: 1 }));
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Put V-17 on hold with a reason, then suspend it again", "Status changes are saved; final status Suspended", async () => {
    let fm = form(page, "/vendors/17/status");
    await fm.locator('select[name="status"]').selectOption("on_hold");
    await fm.locator('[name="reason"]').fill(`QA ${RUN} hold`);
    await submit(fm);
    await expect(main(page)).toContainText(/On hold/);
    fm = form(page, "/vendors/17/status");
    await fm.locator('select[name="status"]').selectOption("suspended");
    await fm.locator('[name="reason"]').fill(`QA ${RUN} back to suspended`);
    await submit(fm);
    await expect(main(page)).toContainText(/Suspended/);
  });
});

test("ADM-10 partner applications: assign, acquirer, review, decide", async ({ page, browser }) => {
  const f = flow(page, R, "ADM-10", "Partner applications", "Admin filters applications, opens one, assigns it to self, records the acquirer, starts review and decides.", "Uses the oldest stale 'E2E Partner' application from an earlier run (not one in use by today's suites).");
  await f.step("Open Applications and filter 'Submitted'", "Applications listed with Oman time", async () => {
    await open(page, "/applications");
    await expect(main(page)).toContainText(/Oman time/);
    await open(page, "/applications?status=submitted");
    await expect(main(page)).toContainText(/APP-/);
  });
  let href = "";
  await f.step("Open the oldest submitted application", "Detail: applicant, documents, checks, actions", async () => {
    const links = await page.locator('main a[href*="/applications/"]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href).filter((h) => /\/applications\/\d+$/.test(h)));
    links.sort((a, b) => Number(a.split("/").pop()) - Number(b.split("/").pop()));
    href = links[0];
    await open(page, href);
  }, { fullPage: true });
  await f.step("Assign to me", "Reviewer shows E2E Admin", async () => {
    const fm = form(page, "/assign");
    if (await fm.count()) await submit(fm);
    await expect(main(page)).toContainText(/E2E Admin/);
  });
  await f.step("Set 'Referred by / account manager' (acquirer)", "Saved; Acquisition shows the account manager", async () => {
    const back = page.url();
    await ensureAccountManager(page, browser);
    await open(page, back);
    const fm = form(page, "/acquirer");
    const opts = await fm.locator("select option").evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent?.trim() })));
    const pick = opts.find((o) => o.v)!;
    await fm.locator("select").selectOption(pick.v);
    await submit(fm);
    await expect(form(page, "/acquirer").locator("select")).toHaveValue(pick.v);
    return `acquirer: ${pick.t}`;
  });
  await f.step("Start review", "Status Under review; decision buttons appear", async () => {
    await submit(form(page, "/decide").filter({ has: page.getByRole("button", { name: /Start review/ }) }));
    await expect(main(page)).toContainText(/Under review/);
  }, { fullPage: true });
  await f.step("Request changes / reject the application", "Decision recorded", async () => {
    const decide = main(page).locator('form[action$="/decide"]');
    const names = await decide.getByRole("button").allInnerTexts();
    const target = decide.filter({ has: page.getByRole("button", { name: /Reject|Request changes/ }) }).first();
    const note = target.locator("textarea, input[type=text]");
    if (await note.count()) await note.first().fill(`QA ${RUN}: documents missing`);
    await submit(target, /Reject|Request changes/);
    await expect(main(page)).toContainText(/Rejected|Changes requested/);
    return `decision buttons: ${names.join(", ")}`;
  });
});

test("ADM-11 approvals queue, customers and document review", async ({ page }) => {
  const f = flow(page, R, "ADM-11", "Approvals queue, customers, documents", "Listing approvals queue (validation only — listing 19 is reserved for other suites), customer status change with reason, document review queue and document types.");
  await f.step("Open Approvals queue", "Pending listings with Approve / Reject (reason) and Oman time", async () => {
    await open(page, "/approvals");
    await expect(main(page)).toContainText(/Oman time/);
  });
  await f.step("Reject a listing with an empty reason", "Refused by validation; listing stays pending", async () => {
    const fm = main(page).locator('form[action*="/approvals/listings/"][action$="/reject"]').first();
    if (!(await fm.count())) return "no pending listing in the queue";
    const action = await fm.getAttribute("action");
    await submitNoValidate(fm);
    await expect(main(page).locator(`form[action="${action}"]`)).toHaveCount(1);
  });
  await f.step("Customers: search e2e-new and filter Active", "Matching customers listed", async () => {
    await open(page, "/customers?q=e2e-new&status=active");
    await expect(main(page)).toContainText("e2e-new-");
  });
  await f.step("Suspend a stale e2e-new customer without a reason (browser check bypassed)", "Refused; the error is shown and the status filter keeps 'Active'", async () => {
    const fm = main(page).locator('form[action*="/customers/"]').last();
    await fm.locator('select[name="status"]').selectOption("suspended");
    await submitNoValidate(fm);
    await expect(page.locator('main [role="alert"]')).toContainText(/reason field is required/i);
    await expect(main(page).locator("form[method=GET] select[name=status], form:not([method=POST]) select[name=status]").first()).toHaveValue("active");
  });
  await f.step("Suspend it with a reason, then reactivate", "Status Suspended then Active again", async () => {
    await open(page, "/customers?q=e2e-new-1794995907992");
    let fm = main(page).locator('form[action*="/customers/"]').first();
    await fm.locator('select[name="status"]').selectOption("suspended");
    await fm.locator('[name="reason"]').fill(`QA ${RUN}`);
    await submit(fm);
    await open(page, "/customers?q=e2e-new-1794995907992");
    await expect(main(page)).toContainText(/Suspended/);
    fm = main(page).locator('form[action*="/customers/"]').first();
    const sel = fm.locator('select[name="status"]');
    await sel.selectOption("active");
    await fm.locator('[name="reason"]').fill(`QA ${RUN} restore`);
    await submit(fm);
    await open(page, "/customers?q=e2e-new-1794995907992");
    await expect(main(page)).toContainText(/Active/);
  });
  await f.step("Document review queue: Pending / Approved / Rejected / Expired tabs", "Each tab loads", async () => {
    for (const s of ["pending", "approved", "rejected", "expired"]) await open(page, `/documents?status=${s}`);
  });
  await f.step("Document types: add type with validation, then valid", "Required errors, then the type is saved", async () => {
    await open(page, "/documents/types");
    const fm = form(page, "/documents/types");
    await submitNoValidate(fm);
    await expectValidation(page);
    const fm2 = form(page, "/documents/types");
    await fm2.locator('[name="code"]').fill(`qa_doc_${RUN}`);
    await fm2.locator('[name="name_en"]').fill(`QA licence ${RUN}`);
    await fm2.locator('[name="name_ar"]').fill(`رخصة ${RUN}`);
    await submit(fm2);
    await expect(main(page)).toContainText(`QA licence ${RUN}`);
  });
});

test("ADM-12 contracts and join options", async ({ page }) => {
  const f = flow(page, R, "ADM-12", "Contracts and join options", "Admin creates a contract template, saves a draft version, publishes it, and reviews the join options (partner wizard toggles).");
  await f.step("Open Contracts", "Template list and Create form", async () => {
    await open(page, "/contracts");
  });
  await f.step("Create template empty", "Name required", async () => {
    await submitNoValidate(form(page, "/contracts"));
    await expectValidation(page);
  });
  await f.step(`Create category-contract template "QA contract ${RUN}"`, "Template created; detail page opens or is listed", async () => {
    const fm = form(page, "/contracts");
    await fm.locator('[name="name"]').fill(`QA contract ${RUN}`);
    // Category contract, not assigned to any category: publishing it gates nobody (a Seller Agreement would make every seller re-sign).
    await fm.locator('select[name="kind"]').selectOption("seller_category");
    await submit(fm);
    if (!/\/contracts\/\d+/.test(page.url())) {
      await main(page).getByRole("link", { name: `QA contract ${RUN}` }).first().click();
    }
    await expect(page).toHaveURL(/\/contracts\/\d+/);
  });
  await f.step("Save a bilingual draft and publish it", "Version published (click-wrap)", async () => {
    const fm = form(page, "/draft");
    await fm.locator('[name="body_en"]').fill(`QA terms ${RUN}`);
    await fm.locator('[name="body_ar"]').fill(`شروط ${RUN}`);
    await fm.locator('[name="change_summary"]').fill("First version");
    await submit(fm);
    const pub = main(page).locator('form[action*="/contracts/versions/"][action$="/publish"]').first();
    await submit(pub);
    await expect(main(page)).toContainText(/Published|published/);
  }, { fullPage: true });
  await f.step("Open Join options", "Toggles for each partner type with Save", async () => {
    await open(page, "/join-options");
    await expect(form(page, "/join-options")).toBeVisible();
  });
  await f.step("Save join options unchanged", "Saved message; no change to the storefront wizards", async () => {
    await submit(form(page, "/join-options"));
    await expect(flash(page)).toBeVisible();
  });
});

void alertBox;
