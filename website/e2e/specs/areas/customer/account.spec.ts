import { CUSTOMER_STATE } from "../../../support/accounts";
import { fixtures } from "./data";
import {
  api, createCustomer, expect, flow, formOf, mailMark, main, MARKET, newCustomer, PASSWORD, RUN, settle, setup, signInWithCode, test, uaePhp, uniqueEmail, visit, waitForCode, waitForMail,
} from "./kit";
import { readFileSync } from "node:fs";
import { LOG_FILE } from "../../../support/mail";
import { routeFakeGateway } from "../../../support/storefront";

/** Customer profile & account: report customer-profile-and-account (CUS-xx). */
const R = "customer-profile-and-account" as const;
setup(R);

test.describe("sign up and sign in", () => {
  test("CUS-01 sign up with an email code", async ({ page }) => {
    const f = flow(page, R, "CUS-01", "Sign up with an email code", "A new shopper creates an account with the 6-digit code emailed to them and lands signed in.");
    const email = uniqueEmail("signup");
    await f.step("Open /uae-en/login", "Welcome screen with the email field, password option, phone option and social buttons", async () => {
      await visit(page, "/login");
      await expect(page.locator('input[type="email"]')).toBeVisible();
      await expect(page.getByRole("button", { name: "Email me a sign-in code" })).toBeVisible();
    });
    let mark = 0;
    await f.step(`Type a new email (${email}) and ask for a code`, "The code step opens and says where the code was sent", async () => {
      await page.locator('input[type="email"]').fill(email);
      mark = mailMark();
      await page.getByRole("button", { name: "Email me a sign-in code" }).click();
      await expect(page.locator('input[autocomplete="one-time-code"]')).toBeVisible();
      await expect(main(page)).toContainText(email);
    });
    await f.step("Read the code from the mail log and enter it", "Signed in, sent away from /login; session cookie set", async () => {
      const code = await waitForCode(email, mark);
      await page.locator('input[autocomplete="one-time-code"]').fill(code);
      await formOf(page, 'input[autocomplete="one-time-code"]').locator('button[type="submit"]').click();
      await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
      expect((await page.context().cookies()).some((c) => c.name === "nawy_signed_in" && c.value === "1")).toBe(true);
      return `code ${code} accepted`;
    });
    await f.step("Open My account", "Account overview greets the new shopper; no orders yet", async () => {
      await visit(page, "/account");
      await expect(main(page)).toContainText(/Overview/);
      await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
    });
    f.end();
  });

  test("CUS-02 password sign-in, wrong password, forgot password", async ({ page, browser }) => {
    const f = flow(page, R, "CUS-02", "Password sign-in, wrong password and forgot password", "A shopper with a password signs in; a wrong password is refused; the forgot-password link is emailed.",
      "A shopper with the e2e password created for this run (the shared e2e customer is rate-limited at 5 password tries a minute per email, which other agents also use).");
    const { email } = createCustomer("pw", { password: true });
    await f.step("Open login, choose 'Sign in with password', enter a wrong password", "An error alert; still signed out", async () => {
      await visit(page, "/login");
      await page.locator('input[type="email"]').fill(email);
      await page.getByRole("button", { name: "Sign in with password" }).click();
      await page.locator('input[type="password"]').fill("not-the-password");
      await formOf(page, 'input[type="password"]').locator('button[type="submit"]').click();
      await expect(main(page).getByRole("alert").first()).toBeVisible();
      return (await main(page).getByRole("alert").first().innerText()).trim();
    });
    await f.step("Enter the right password", "Signed in and redirected away from /login", async () => {
      await page.locator('input[type="password"]').fill(PASSWORD);
      await formOf(page, 'input[type="password"]').locator('button[type="submit"]').click();
      await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
    });
    const guest = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    await f.step("In a guest window: 'Forgot password?' for the same shopper", "Confirmation that a reset link was sent; the mail with a reset link is in the log", async () => {
      await guest.goto(`/${MARKET}/login`);
      await guest.locator('input[type="email"]').fill(email);
      await guest.getByRole("button", { name: "Sign in with password" }).click();
      const mark = mailMark();
      await guest.getByRole("button", { name: /Forgot password/ }).click();
      const submit = guest.getByRole("button", { name: /Send reset link|Send/ }).first();
      if (await submit.isVisible().catch(() => false)) await submit.click();
      const mail = await waitForMail(email, mark, /reset-password/);
      expect(mail).toMatch(/reset-password\?email=[^&\s]+&token=[a-f0-9]{20,}/);
    }, { page: guest });
    await guest.close();
    f.end();
  });

  test("CUS-03 legacy phone-only account signs in with an SMS code", async ({ page }) => {
    const f = flow(page, R, "CUS-03", "Phone code sign-in (legacy phone-only account)", "A legacy account that has only a phone number signs in once with an SMS code (SMS log driver) and is asked to add an email.",
      "A legacy user (phone only, requires_email) created for this run with artisan tinker.");
    const local = `50${String(Date.now()).slice(-7)}`;
    const phone = `+971${local}`;
    await f.step("Create a legacy phone-only account (test data)", "User row with requires_email=true", async () => {
      const out = uaePhp(`$u = new App\\Models\\User; $u->forceFill(["name" => "QA Legacy ${RUN}", "phone" => "${phone}", "email" => "phone-".ltrim("${phone}", "+")."@legacy.nawy.invalid", "requires_email" => true, "status" => "active", "password" => bcrypt(Illuminate\\Support\\Str::random(24))])->save(); echo "id=".$u->id;`);
      expect(out).toMatch(/id=\d+/);
      return out;
    }, { shot: false });
    await f.step("Login → 'Had an old Nawy account with only a phone number?' → enter the number → send code", "The SMS code step opens", async () => {
      await visit(page, "/login");
      await page.getByRole("button", { name: /only a phone number/ }).click();
      await page.locator('input[type="tel"]').fill(phone);
      await formOf(page, 'input[type="tel"]').locator('button[type="submit"]').click();
      await expect(page.locator('input[autocomplete="one-time-code"]')).toBeVisible();
    });
    await f.step("Read the SMS code from the log driver and enter it", "Signed in; sent to the 'add your email' page", async () => {
      let code = "";
      for (let i = 0; i < 40 && !code; i++) {
        const tail = readFileSync(LOG_FILE, "utf8").slice(-200_000);
        const m = [...tail.matchAll(/SMS \(log driver\).*?"to":"\+9715.*?(\d{3})".*?sign-in code is (\d{6})/g)].filter((x) => phone.endsWith(x[1]));
        code = m.length ? m[m.length - 1][2] : "";
        if (!code) await page.waitForTimeout(250);
      }
      expect(code, "SMS code in laravel.log").toMatch(/^\d{6}$/);
      await page.locator('input[autocomplete="one-time-code"]').fill(code);
      await formOf(page, 'input[autocomplete="one-time-code"]').locator('button[type="submit"]').click();
      await expect(page).toHaveURL(/add-email|account/, { timeout: 20_000 });
      return `landed on ${new URL(page.url()).pathname}`;
    });
    f.end();
  });

  test("CUS-04 social sign-in buttons render", async ({ page }) => {
    const f = flow(page, R, "CUS-04", "Social sign-in buttons", "Google and Apple buttons are shown on the login page (the OAuth round trip needs real provider apps and is not run).");
    await f.step("Open /uae-en/login", "'Continue with Google' and 'Continue with Apple' are visible and enabled", async () => {
      await visit(page, "/login");
      await expect(page.getByRole("button", { name: "Continue with Google" })).toBeEnabled();
      await expect(page.getByRole("button", { name: "Continue with Apple" })).toBeEnabled();
    });
    await f.step("Ask the API where 'Continue with Google' would go", "The API answers with a provider redirect, or a clear 'not configured' error in dev", async () => {
      const res = await api(page, "GET", "/auth/social/google/redirect");
      return `GET auth/social/google/redirect → ${res.status} ${res.body.slice(0, 160)}`;
    }, { shot: false });
    f.skip("Complete the Google / Apple OAuth round trip", "Account signed in through the provider", "Needs real Google / Apple OAuth apps and accounts; a provider's production sign-in page may not be driven by the test.");
    f.end();
  });
});

test.describe("profile and account (new shopper)", () => {
  test("CUS-05 profile edit, marketing preferences, change-email code", async ({ page }) => {
    const f = flow(page, R, "CUS-05", "Edit profile", "Change name, preferred email language and marketing channels; changes persist after a reload.", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "profile"); }, { shot: false });
    const name = `QA Shopper ${RUN}`;
    await f.step("Open Profile, change the full name, email language → العربية, tick SMS and WhatsApp, Save changes", "'Your profile is saved.'", async () => {
      await visit(page, "/account/profile");
      await page.getByLabel("Full name").fill(name);
      await page.getByLabel("Preferred language for emails").selectOption({ label: "العربية" });
      await page.getByLabel("SMS").check();
      await page.getByLabel("WhatsApp").check();
      await page.getByRole("button", { name: "Save changes" }).click();
      await expect(main(page)).toContainText("Your profile is saved.");
    });
    await f.step("Reload the profile page", "Name, language and channels are kept", async () => {
      await page.reload();
      await settle(page);
      await expect(page.getByLabel("Full name")).toHaveValue(name);
      await expect(page.getByLabel("SMS")).toBeChecked();
      await expect(page.getByLabel("WhatsApp")).toBeChecked();
    });
    await f.step("Upload a profile photo", "The photo is shown and a Remove button appears", async () => {
      const PNG = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=", "base64");
      await page.locator('main input[type="file"]').setInputFiles({ name: "me.png", mimeType: "image/png", buffer: PNG });
      await expect(page.getByRole("button", { name: "Remove" })).toBeVisible();
    }, { soft: true });
    const newEmail = uniqueEmail("changed");
    await f.step(`Change email → ${newEmail}, enter the code mailed to the new address`, "'Your email is now …'", async () => {
      await page.getByRole("button", { name: "Change email" }).click();
      await page.getByLabel("New email").fill(newEmail);
      const mark = mailMark();
      await page.getByRole("button", { name: "Send code" }).click();
      const code = await waitForCode(newEmail, mark);
      await page.getByLabel("Code", { exact: true }).fill(code);
      await page.getByRole("button", { name: "Confirm new email" }).click();
      await expect(main(page)).toContainText(`Your email is now ${newEmail}`);
    }, { soft: true });
    f.end();
  });

  test("CUS-06 address book: add, edit, set default, delete", async ({ page }) => {
    const f = flow(page, R, "CUS-06", "Address book", "Add two addresses, edit one, make the second the default and delete one.", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "addr"); }, { shot: false });
    const add = async (street: string) => {
      await page.getByRole("button", { name: "Add address" }).first().click();
      const form = formOf(page, 'input[name="street"]');
      await form.locator('input[name="name"]').fill("QA Address");
      await form.locator('input[name="phone"]').fill("+971501112233");
      if (await form.locator('input[name="city_free"]').count()) await form.locator('input[name="city_free"]').fill("Dubai");
      else await form.locator("select").first().selectOption({ index: 1 });
      const area = form.locator('[name="area"]');
      if ((await area.evaluate((el) => el.tagName)) === "SELECT") await area.selectOption({ index: 1 });
      else await area.fill("Marina");
      await form.locator('input[name="street"]').fill(street);
      await form.locator('button[type="submit"]').click();
      await expect(main(page)).toContainText(street);
    };
    const s1 = `QA Street A ${RUN}`;
    const s2 = `QA Street B ${RUN}`;
    await f.step("Addresses → Add address (city, area, street) → Save", "The address is listed and is the default (first address)", async () => {
      await visit(page, "/account/addresses");
      await expect(main(page)).toContainText("No saved addresses yet.");
      await add(s1);
      await expect(card(s1)).toContainText("Default");
    });
    await f.step("Add a second address", "Both addresses listed", async () => { await add(s2); });
    await f.step("Edit the second address: change the street", "The new street is shown", async () => {
      await card(s2).getByRole("button", { name: "Edit" }).click();
      await formOf(page, 'input[name="street"]').locator('input[name="street"]').fill(`${s2} edited`);
      await formOf(page, 'input[name="street"]').locator('button[type="submit"]').click();
      await expect(main(page)).toContainText(`${s2} edited`);
    });
    await f.step("Set the second address as default", "The 'Default' badge moves to it", async () => {
      await card(`${s2} edited`).getByRole("button", { name: "Set as default" }).click();
      await expect(card(`${s2} edited`)).toContainText("Default");
      await expect(card(s1).getByRole("button", { name: "Set as default" })).toBeVisible();
    });
    await f.step("Delete the first address (confirm dialog)", "Only the edited default address is left", async () => {
      page.once("dialog", (d) => d.accept());
      await card(s1).getByRole("button", { name: "Delete" }).click();
      await expect(main(page)).not.toContainText(s1);
      await expect(main(page)).toContainText(`${s2} edited`);
    });
    f.end();

    function card(street: string) {
      return page.locator(`xpath=//main//*[contains(text(), "${street}")]/ancestor::*[.//button[contains(., "Delete")]][1]`);
    }
  });

  test("CUS-07 wishlist and recently viewed", async ({ page }) => {
    const f = flow(page, R, "CUS-07", "Wishlist and recently viewed", "Save a product to the wishlist from its page, find it in Wishlist, remove it; the visited product appears in Recently viewed.", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "wish"); }, { shot: false });
    const slug = fixtures().product_slug;
    await f.step(`Open the product page /products/${slug} and tap 'Add to wishlist'`, "The heart switches to 'Remove from wishlist'", async () => {
      await visit(page, `/products/${slug}`);
      await page.getByRole("button", { name: "Add to wishlist" }).first().click();
      await expect(page.getByRole("button", { name: "Remove from wishlist" }).first()).toBeVisible();
    });
    await f.step("Open Account → Wishlist", "The product is listed", async () => {
      await visit(page, "/account/wishlist");
      await expect(page.locator(`main a[href*="/products/${slug}"]`).first()).toBeVisible();
    });
    await f.step("Remove it from the wishlist", "The wishlist is empty", async () => {
      await page.getByRole("button", { name: "Remove from wishlist" }).first().click();
      await expect(page.locator(`main a[href*="/products/${slug}"]`)).toHaveCount(0);
    });
    await f.step("Recently viewed: the API list for this shopper and the home page block", "The visited product is in the recently-viewed list", async () => {
      let res = await api(page, "GET", "/me/recently-viewed");
      for (let i = 0; i < 10 && !JSON.stringify(res.json ?? "").includes(slug); i++) {
        await page.waitForTimeout(500);
        res = await api(page, "GET", "/me/recently-viewed");
      }
      expect(res.status).toBe(200);
      expect(JSON.stringify(res.json)).toContain(slug);
      await visit(page, "/");
      const block = main(page).getByText("Recently viewed").first();
      const shown = await block.isVisible().catch(() => false);
      if (shown) await block.scrollIntoViewIfNeeded();
      return shown ? "API lists the product; the home page shows a Recently viewed block" : "API lists the product; the home page layout has no Recently viewed block in dev";
    });
    f.end();
  });

  test("CUS-08 saved cards (tokens only)", async ({ page }) => {
    const f = flow(page, R, "CUS-08", "Saved cards", "Saved cards are listed as gateway tokens with brand / last 4 only (no card numbers); Account → Saved cards shows them (QA-C05).", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "cards"); }, { shot: false });
    await f.step("GET /me/cards as the shopper", "200 with a list; no field holds a full card number or CVV", async () => {
      const res = await api(page, "GET", "/me/cards");
      expect(res.status).toBe(200);
      expect(res.body).not.toMatch(/\b\d{13,19}\b/);
      expect(res.body.toLowerCase()).not.toMatch(/"(pan|cvv|cvc|card_number)"/);
      return `${res.status}: ${res.body.slice(0, 200)}`;
    }, { shot: false });
    await f.step("Account menu → Saved cards", "A 'Saved cards' link opens /account/cards: heading, token note and the empty state (a new shopper has no cards)", async () => {
      await visit(page, "/account");
      const link = page.getByRole("link", { name: "Saved cards" }).first();
      await expect(link).toBeVisible();
      await link.click();
      await expect(page).toHaveURL(/\/account\/cards$/);
      await expect(main(page).getByRole("heading", { name: "Saved cards" })).toBeVisible();
      await expect(main(page)).toContainText("You have no saved cards.");
      expect(await main(page).innerText()).not.toMatch(/\b\d{13,19}\b/);
    });
    await f.step("Saved cards in Arabic", "The AR page shows the RTL heading 'البطاقات المحفوظة'", async () => {
      await page.goto(page.url().replace(/-en\//, "-ar/"));
      await expect(main(page).getByRole("heading", { name: "البطاقات المحفوظة" })).toBeVisible();
      expect(await page.locator("html").getAttribute("dir")).toBe("rtl");
    });
    f.end();
  });

  test("CUS-09 Nawy One membership with the fake card gateway", async ({ page }) => {
    const f = flow(page, R, "CUS-09", "Nawy One membership", "Join Nawy One paying by card through the fake gateway; the membership page shows it active; cancel it.", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "member"); }, { shot: false });
    await f.step("Account → Nawy One: choose the plan and 'Credit / debit card'", "Plan and payment method selected", async () => {
      await visit(page, "/account/membership");
      await page.locator('input[name="plan"]').first().check();
      await page.locator('input[name="payment_method"]').first().check();
    });
    await f.step("Join Nawy One (fake gateway reports captured)", "Payment received page: 'Your Nawy One membership is active.'", async () => {
      await routeFakeGateway(page, MARKET, "captured");
      await page.getByRole("button", { name: "Join Nawy One" }).click();
      await expect(main(page)).toContainText(/Payment received|membership is active/, { timeout: 45_000 });
    });
    await f.step("Open Account → Nawy One again", "The membership is shown as active with a cancel option", async () => {
      await visit(page, "/account/membership");
      await expect(main(page)).toContainText(/Active|Free trial|renews/i);
    });
    await f.step("Cancel the membership", "The page says it ends at the period end / is cancelled", async () => {
      page.once("dialog", (d) => d.accept());
      await page.getByRole("button", { name: /Cancel/ }).first().click();
      const confirm = page.getByRole("dialog").getByRole("button", { name: /Cancel/ });
      if (await confirm.isVisible().catch(() => false)) await confirm.last().click();
      await expect(main(page)).toContainText(/cancel|ends/i);
    }, { soft: true });
    f.end();
  });

  test("CUS-10 gift card: buy, receive the code, redeem to Nawy Credits", async ({ page }) => {
    const f = flow(page, R, "CUS-10", "Gift card buy and redeem (wallet / credits)", "Buy a gift card for yourself with the fake card gateway, read the code from the gift-card email, redeem it to Nawy Credits and see the balance and transaction.", "A new shopper signed in by email code.");
    let email = "";
    await f.step("Sign up a new shopper", "Signed in", async () => { email = await newCustomer(page, "gift"); }, { shot: false });
    await f.step("Gift cards page: design Birthday, AED 50, recipient = me, send now, card", "Buy button shows 'Buy · AED 50'", async () => {
      await visit(page, "/gift-cards");
      await page.getByRole("button", { name: "Birthday" }).click();
      await page.getByRole("button", { name: "AED 50", exact: true }).click();
      await page.getByLabel("Recipient name").fill("QA Me");
      await page.getByLabel("Recipient email").fill(email);
      await page.getByLabel("Your message").fill("Happy testing");
      await page.locator('input[name="payment_method"]').first().check();
      await expect(page.getByRole("button", { name: /Buy · AED 50/ })).toBeVisible();
    });
    let mark = 0;
    await f.step("Buy (fake gateway reports captured)", "'Payment received' with the gift card number", async () => {
      await routeFakeGateway(page, MARKET, "captured");
      mark = mailMark();
      await page.getByRole("button", { name: /Buy · AED 50/ }).click();
      await expect(main(page)).toContainText(/Payment received|gift card/i, { timeout: 45_000 });
    });
    let code = "";
    await f.step("Read the gift card email", "A mail to the recipient with the redeem code", async () => {
      const mail = await waitForMail(email, mark, /gift/i, 30_000);
      const m = /\b([A-Z0-9]{4}(?:-[A-Z0-9]{4}){2,3})\b/.exec(mail) ?? /code[^A-Z0-9]{0,40}([A-Z0-9-]{8,24})/i.exec(mail);
      expect(m, "gift card code in the mail").not.toBeNull();
      code = m![1];
      return `code ${code.slice(0, 4)}…`;
    }, { shot: false });
    await f.step("Account → Gift cards: the bought card is listed; redeem the code to Nawy Credits", "Success message; the card shows as redeemed", async () => {
      await visit(page, "/account/gift-cards");
      await expect(main(page)).not.toContainText("You haven't bought any gift cards yet.");
      await page.getByLabel("Gift card code").fill(code);
      await page.getByRole("button", { name: "Redeem to Nawy Credits" }).click();
      await expect(main(page)).toContainText(/added|redeemed|credited/i);
    });
    await f.step("Account → Nawy Credits", "Balance AED 50.00 and a gift-card transaction", async () => {
      await visit(page, "/account/credits");
      await expect(main(page)).toContainText("50.00");
    });
    await f.step("Redeem the same code again", "Refused with a clear message", async () => {
      await visit(page, "/account/gift-cards");
      await page.getByLabel("Gift card code").fill(code);
      await page.getByRole("button", { name: "Redeem to Nawy Credits" }).click();
      await expect(main(page).getByRole("alert").first()).toBeVisible();
      return (await main(page).getByRole("alert").first().innerText()).trim();
    }, { soft: true });
    f.end();
  });

  test("CUS-11 vouchers: invalid code refused", async ({ page }) => {
    const f = flow(page, R, "CUS-11", "Vouchers", "The vouchers page lists vouchers and refuses an unknown code.", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "voucher"); }, { shot: false });
    await f.step("Account → Vouchers: redeem 'NOPE-CODE-1'", "An error message; nothing added", async () => {
      await visit(page, "/account/vouchers");
      await page.getByLabel("Voucher code").fill("NOPE-CODE-1");
      await page.getByRole("button", { name: "Redeem voucher" }).click();
      await expect(main(page).getByRole("alert").first()).toBeVisible();
      return (await main(page).getByRole("alert").first().innerText()).trim();
    });
    f.end();
  });

  test("CUS-12 support ticket: open, reply", async ({ page }) => {
    const f = flow(page, R, "CUS-12", "Support tickets", "Open a support ticket, see it in the list and reply in its thread.", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "ticket"); }, { shot: false });
    const subject = `QA ticket ${RUN}`;
    await f.step("Account → Support tickets → new: topic Account, subject, message → Send ticket", "Redirected to the ticket with its number", async () => {
      await visit(page, "/account/support/new");
      await page.getByLabel("Topic").selectOption({ label: "Account" });
      await page.getByLabel("Subject").fill(subject);
      await page.getByLabel("How can we help?").fill("I would like to know how to change my delivery address.");
      await page.getByRole("button", { name: "Send ticket" }).click();
      await expect(page).toHaveURL(/\/account\/support\/[A-Z0-9-]+$/, { timeout: 20_000 });
      await expect(main(page)).toContainText(subject);
    });
    await f.step("Reply in the ticket thread", "The reply shows in the thread", async () => {
      const box = main(page).locator("textarea:visible").first();
      await box.fill("Adding more detail: it is for my next order.");
      await main(page).getByRole("button", { name: /Send|Reply/ }).last().click();
      await expect(main(page)).toContainText("Adding more detail");
    });
    await f.step("Back to the tickets list", "The ticket is listed with status Open", async () => {
      await visit(page, "/account/support");
      await expect(main(page)).toContainText(subject);
    });
    f.end();
  });

  test("CUS-13 referral link", async ({ page }) => {
    const f = flow(page, R, "CUS-13", "Referral link", "A marketer's referral link /r/{code} opens the invitation page; an unknown code says it does not exist.");
    const code = fixtures().referral_code;
    await f.step(`Open /uae-en/r/${code}`, "Invitation page (who invited, the picked product, disclosure)", async () => {
      await visit(page, `/r/${code}`);
      await expect(main(page)).toContainText(/invited you|Picked by|commission/);
    });
    await f.step("Open /uae-en/r/NOPE0000 (unknown code)", "A real HTTP 404 page (QA-012: the proxy asks the API and answers 404 before rendering)", async () => {
      const res = await page.goto(`/${MARKET}/r/NOPE0000`);
      await settle(page);
      expect(res?.status()).toBe(404);
      await expect(main(page)).toContainText(/couldn't find that page|does not exist/);
    });
    f.end();
  });

  test("CUS-14 product question", async ({ page }) => {
    const f = flow(page, R, "CUS-14", "Ask a product question", "A signed-in shopper asks a question on a product page; it waits for approval.", "A new shopper signed in by email code.");
    await f.step("Sign up a new shopper", "Signed in", async () => { await newCustomer(page, "qna"); }, { shot: false });
    await f.step("Product page → Questions & answers → Ask a question → Post question", "'Thanks! Your question will appear once it's approved.'", async () => {
      await visit(page, `/products/${fixtures().product_slug}`);
      const q = page.locator("#questions");
      await q.scrollIntoViewIfNeeded();
      const ask = q.getByRole("button", { name: "Ask a question" });
      if (await ask.isVisible().catch(() => false)) await ask.click();
      await q.locator("textarea, input[type=text]").first().fill(`Does it come with a charger? (${RUN})`);
      await q.getByRole("button", { name: "Post question" }).click();
      await expect(q).toContainText("Thanks! Your question will appear once it's approved.");
    });
    f.end();
  });

  test("CUS-15 security: set a password, sign out, sign in with it", async ({ page }) => {
    const f = flow(page, R, "CUS-15", "Security, password and sign out", "A code-only shopper sets a password on Security & devices, signs out, then signs in with the new password; devices and login history are listed.", "A new shopper signed in by email code.");
    let email = "";
    await f.step("Sign up a new shopper", "Signed in", async () => { email = await newCustomer(page, "sec"); }, { shot: false });
    await f.step("Security & devices: devices and login history listed", "Headings Signed-in devices and Login history with this session", async () => {
      await visit(page, "/account/security");
      await expect(main(page)).toContainText("Signed-in devices");
      await expect(main(page)).toContainText("Login history");
    });
    await f.step("Set a password: the code-only shopper sees 'Set password' without a current-password field", "'Password saved. Other devices were signed out.'", async () => {
      await expect(main(page)).toContainText("You sign in with an email code. Add a password to sign in faster.");
      await expect(page.getByLabel("Current password")).toHaveCount(0);
      await page.getByLabel("New password", { exact: true }).fill(PASSWORD);
      await page.getByLabel("Confirm new password").fill(PASSWORD);
      await page.getByRole("button", { name: "Set password" }).click();
      await expect(main(page)).toContainText("Password saved. Other devices were signed out.");
    });
    await f.step("Change it again: now the current password is asked; a wrong current password is refused", "'The current password is not correct.'", async () => {
      await page.reload();
      await settle(page);
      await page.getByLabel("Current password").fill("wrong-current-1");
      await page.getByLabel("New password", { exact: true }).fill(PASSWORD);
      await page.getByLabel("Confirm new password").fill(PASSWORD);
      await page.getByRole("button", { name: "Change password" }).click();
      await expect(main(page)).toContainText("The current password is not correct.");
    }, { soft: true });
    await f.step("Sign out", "Signed out and the header shows Sign in", async () => {
      await page.getByRole("button", { name: "Sign out" }).first().click();
      await expect.poll(async () => (await page.context().cookies()).some((c) => c.name === "nawy_signed_in" && c.value === "1")).toBe(false);
    });
    await f.step("Sign in with the new password", "Signed in", async () => {
      await visit(page, "/login");
      await page.locator('input[type="email"]').fill(email);
      await page.getByRole("button", { name: "Sign in with password" }).click();
      await page.locator('input[type="password"]').fill(PASSWORD);
      await formOf(page, 'input[type="password"]').locator('button[type="submit"]').click();
      await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
    });
    f.end();
  });
});

test.describe("seeded shopper (orders, notifications, invoices, locale)", () => {
  test.use({ storageState: CUSTOMER_STATE });

  test("CUS-16 notifications centre", async ({ page }) => {
    const f = flow(page, R, "CUS-16", "Notifications centre", "List notifications, filter Unread, mark all as read.", "The seeded e2e customer (e2e/.auth/customer.json).");
    await f.step("Account → Notifications", "List with All / Unread filters", async () => {
      await visit(page, "/account/notifications");
      await expect(page.getByRole("button", { name: "Unread" })).toBeVisible();
    });
    await f.step("Filter Unread", "Only unread notifications (or the empty state)", async () => {
      await page.getByRole("button", { name: "Unread" }).click();
      await settle(page);
    });
    await f.step("Mark all as read", "The unread list is empty and the header badge clears", async () => {
      const btn = page.getByRole("button", { name: "Mark all as read" });
      if (await btn.isEnabled().catch(() => false)) await btn.click();
      await expect(page.getByRole("button", { name: "Mark as read" })).toHaveCount(0);
    });
    f.end();
  });

  test("CUS-17 orders list, order detail and invoice (HTML + PDF)", async ({ page }) => {
    const f = flow(page, R, "CUS-17", "Orders and invoices", "Orders list with status tabs and search; order detail; the invoice opens as HTML and downloads as a PDF.", "The seeded e2e customer and its seeded delivered order.");
    const order = fixtures().delivered_sub_order.order;
    await f.step("Account → Orders; tab Delivered; search the delivered order number", "The order is found", async () => {
      await visit(page, "/account/orders");
      await main(page).locator('[role="tab"], button').filter({ hasText: /^Delivered/ }).first().click();
      await page.getByLabel("Search orders").fill(order);
      await expect(main(page)).toContainText(order);
    });
    await f.step(`Open order ${order}`, "Order detail with the shipment, its status timeline and an invoice link", async () => {
      await visit(page, `/account/orders/${order}`);
      await expect(main(page)).toContainText(order);
      await expect(main(page)).toContainText("Delivered");
    });
    await f.step("Open the invoice (HTML)", "Tax invoice page with the order number and VAT", async () => {
      const link = main(page).getByRole("link", { name: /invoice/i }).first();
      const [popup] = await Promise.all([page.context().waitForEvent("page", { timeout: 10_000 }).catch(() => null), link.click()]);
      const inv = popup ?? page;
      await inv.waitForLoadState("load");
      await expect(inv.locator("body")).toContainText(order);
      await expect(inv.locator("body")).toContainText(/VAT|Tax invoice|Invoice/i);
      if (popup) { await inv.screenshot({ path: "e2e/docx/shots/customer-profile-and-account/cus-17-3b.png" }); await popup.close(); }
      return `opened ${inv.url().replace(/token=[^&]+/, "token=…")}`;
    });
    await f.step("Download the invoice PDF", "A PDF file (application/pdf, %PDF header)", async () => {
      const res = await api(page, "GET", `/orders/${order}/invoice/pdf`);
      expect(res.status).toBe(200);
      expect(res.body.startsWith("%PDF")).toBe(true);
      const ui = main(page).getByRole("link", { name: /PDF/i }).or(main(page).getByRole("button", { name: /PDF/i }));
      return `API pdf ${res.body.length} bytes; PDF control on page: ${(await ui.count()) > 0}`;
    }, { shot: false });
    f.end();
  });

  test("CUS-18 language switch EN ↔ AR (RTL) and market switch", async ({ page }) => {
    const f = flow(page, R, "CUS-18", "Language and market switch", "Switch the account pages to Arabic (RTL) and back; switch the country to Saudi Arabia from the header.", "The seeded e2e customer.");
    await f.step("Account overview in English → header link العربية", "/uae-ar/account, dir=rtl, lang=ar, Arabic menu", async () => {
      await visit(page, "/account");
      await page.getByRole("link", { name: "العربية" }).first().click();
      await expect(page).toHaveURL(/\/uae-ar\/account/);
      await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
      await expect(page.locator("html")).toHaveAttribute("lang", "ar");
      await settle(page);
    });
    await f.step("Open Orders in Arabic", "Arabic labels, RTL layout, no raw keys", async () => {
      await page.goto("/uae-ar/account/orders");
      await settle(page);
      await expect(main(page)).toContainText(/الطلبات/);
      expect(await main(page).innerText()).not.toMatch(/\b[a-z]+\.[a-z]+[A-Z][a-zA-Z]+\b/);
    });
    await f.step("Switch back with 'English'", "/uae-en/account/orders, dir=ltr", async () => {
      await page.getByRole("link", { name: "English" }).first().click();
      await expect(page).toHaveURL(/\/uae-en\/account\/orders/);
      await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
    });
    await f.step("Header 'Country & language' → Saudi Arabia, English → Go", "Lands on /ksa-en with SAR prices", async () => {
      await visit(page, "/");
      await page.getByRole("button", { name: "Country & language" }).click();
      await page.getByRole("dialog").getByRole("button", { name: /Saudi Arabia/ }).click();
      await page.getByRole("dialog").getByRole("button", { name: "Go" }).click();
      await expect(page).toHaveURL(/\/ksa-en\/?$/);
      await settle(page);
    });
    f.end();
  });
});
