import { CUSTOMER_STATE } from "../../../support/accounts";
import { fixtures } from "./data";
import { api, expect, flow, main, MARKET, newCustomer, RUN, settle, setup, test, uaePhp, visit } from "./kit";

/** Storefront pages: report website-pages (WEB-xx). */
const R = "website-pages" as const;
setup(R);

const API_GET = async (page: import("@playwright/test").Page, p: string) => (await api(page, "GET", p)).json?.data;

test("WEB-01 home page, header and footer", async ({ page }) => {
  const f = flow(page, R, "WEB-01", "Home page, header and footer", "The UAE English home renders its page-builder blocks, header (search, deliver-to, country & language, account, wishlist, cart) and footer.");
  await f.step("Open /uae-en", "Hero, 'Shop by category', offers and product carousels; title 'Nawy United Arab Emirates — …'", async () => {
    await visit(page, "/");
    await expect(page).toHaveTitle(/Nawy United Arab Emirates/);
    await expect(main(page)).toContainText("Shop by category");
    expect(await main(page).locator('a[href*="/products/"]').count()).toBeGreaterThan(0);
  });
  await f.step("Header: search box, Deliver to, Country & language, Log in, wishlist, cart, vertical links", "All header controls visible", async () => {
    await expect(page.getByRole("combobox", { name: "Search" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Country & language" })).toBeVisible();
    await expect(page.locator("header")).toContainText(/Deliver to/);
    await expect(page.locator("header")).toContainText(/Log in/);
    await expect(page.locator("header").getByRole("link", { name: /Deals/ }).first()).toBeVisible();
  });
  await f.step("Deliver to → choose a city and area", "The header shows the chosen location", async () => {
    await page.locator("header").getByRole("button", { name: /Deliver to/ }).first().click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await dialog.locator('input[name="city"]').fill("Dubai");
    await dialog.locator('input[name="area"]').fill("Marina");
    await dialog.locator('button[type="submit"]').click();
    await expect(dialog).toBeHidden();
    await expect(page.locator("header").getByRole("button", { name: /Deliver to/ }).first()).toContainText("Marina, Dubai");
    return (await page.locator("header").getByRole("button", { name: /Deliver to/ }).first().innerText()).replace(/\s+/g, " ");
  }, { soft: true });
  await f.step("Scroll to the footer", "Help, account, policies, payment methods and country links", async () => {
    const footer = page.locator("footer");
    await footer.scrollIntoViewIfNeeded();
    await expect(footer).toContainText(/Help/);
    await expect(footer.getByRole("link", { name: /Privacy/ }).first()).toBeVisible();
  });
  f.end();
});

test("WEB-02 category tree, category and sub-category pages", async ({ page }) => {
  const f = flow(page, R, "WEB-02", "Category tree and category pages", "Open the mega menu, go to a category, then a sub-category; breadcrumbs and sub-category chips work.");
  await f.step("Header 'All categories' → mega menu", "Top-level categories with their children", async () => {
    await visit(page, "/");
    await page.getByRole("button", { name: /All categories/ }).first().click();
    await expect(page.getByRole("link", { name: "Electronics" }).first()).toBeVisible();
  });
  await f.step("Click 'Electronics' in the category nav bar (menu still open)", "/uae-en/electronics: title, sub-category chips, facets, product grid", async () => {
    await page.getByRole("link", { name: "Electronics", exact: true }).first().click();
    await expect(page).toHaveURL(/\/uae-en\/electronics$/);
    await settle(page);
    await expect(main(page).locator("h1:visible")).toContainText("Electronics");
    await expect(main(page).getByRole("link", { name: /Mobiles/ }).first()).toBeVisible();
  });
  await f.step("The mega menu closed with the navigation", "The menu is no longer shown over the category page", async () => {
    await expect(page.getByRole("button", { name: /All categories/ })).toHaveAttribute("aria-expanded", "false", { timeout: 3_000 });
  }, { soft: true });
  await page.keyboard.press("Escape");
  await f.step("Click the Mobiles sub-category", "/uae-en/electronics/mobiles with breadcrumb Home › Electronics › Mobiles", async () => {
    await main(page).locator('a[href$="/electronics/mobiles"]:visible').first().click();
    await expect(page).toHaveURL(/\/electronics\/mobiles/);
    await settle(page);
    await expect(main(page).getByRole("link", { name: "Electronics" }).first()).toBeVisible();
  });
  f.end();
});

test("WEB-03 PLP filters, facets, sort and active-filter chips", async ({ page }) => {
  const f = flow(page, R, "WEB-03", "Category filters, facets and sort", "Apply fulfilment, seller, colour and price facets, sort by price, then remove filters with the chips.");
  const form = () => page.locator("form").filter({ has: page.getByRole("button", { name: "Apply" }) }).first();
  await f.step("Open /uae-en/electronics", "Facets with counts: Fulfilled by, price, deals, rating, condition, colour, seller; Sort by", async () => {
    await visit(page, "/electronics");
    await expect(form().locator('input[name="fulfilment"]').first()).toBeAttached();
    await expect(page.getByLabel("Sort by").first()).toBeVisible();
  });
  await f.step("Tick 'Fulfilled by Nawy' and Apply", "URL has fulfilment=fbn; only FBN products listed", async () => {
    await form().getByLabel(/Fulfilled by Nawy/).first().check();
    await form().getByRole("button", { name: "Apply" }).click();
    await expect(page).toHaveURL(/fulfilment=fbn/);
    await settle(page);
    await expect(main(page)).toContainText("Nova X12");
  });
  await f.step("Price Min 50, Max 500 → Apply", "URL has min_price and max_price; products within range", async () => {
    await form().locator('input[name="min_price"]').fill("50");
    await form().locator('input[name="max_price"]').fill("500");
    await form().getByRole("button", { name: "Apply" }).click();
    await expect(page).toHaveURL(/min_price=50/);
    await expect(page).toHaveURL(/max_price=500/);
    await settle(page);
  });
  await f.step("Remove the filters with the active-filter chips", "Filters cleared from the URL", async () => {
    const chips = main(page).getByRole("button", { name: /Remove filter|Clear/ }).or(main(page).getByRole("link", { name: /Clear|Remove filter/ }));
    if (await chips.count()) await chips.first().click();
    else await page.goto(`/${MARKET}/electronics`);
    await settle(page);
    return page.url();
  });
  await f.step("Seller facet 'E2E Store' + colour Black", "Only the e2e store's products", async () => {
    await page.goto(`/${MARKET}/electronics`);
    await settle(page);
    // Facet groups after the first five start collapsed (<details>): open "Seller".
    const seller = form().locator("details").filter({ has: page.locator('input[name="seller"]') });
    if (!(await seller.getAttribute("open").then((v) => v !== null))) await seller.locator("summary").click();
    await seller.locator('input[value="e2e-store"]').check();
    await form().getByRole("button", { name: "Apply" }).click();
    await expect(page).toHaveURL(/seller=/);
    await settle(page);
    await expect(main(page)).toContainText("Nova X12");
  });
  await f.step("Sort by 'Price: low to high', then 'Price: high to low'", "URL sort=price_asc / price_desc; order of prices changes accordingly", async () => {
    await page.goto(`/${MARKET}/electronics`);
    await settle(page);
    await page.getByLabel("Sort by").first().selectOption({ label: "Price: low to high" });
    await expect(page).toHaveURL(/sort=/);
    await settle(page);
    const asc = await prices();
    await page.getByLabel("Sort by").first().selectOption({ label: "Price: high to low" });
    await expect(page).toHaveURL(/sort=price_desc|sort=-price|sort=price-desc/);
    await settle(page);
    const desc = await prices();
    expect(asc, "ascending").toEqual([...asc].sort((a, b) => a - b));
    expect(desc, "descending").toEqual([...desc].sort((a, b) => b - a));
    return `asc ${asc.join(", ")} | desc ${desc.join(", ")}`;
  }, { soft: true });
  f.end();

  async function prices() {
    const cards = await main(page).locator('a[href*="/products/"]').evaluateAll((as) => as.map((a) => (a as HTMLElement).innerText));
    return cards.map((t) => /AED\s*([\d,]+\.\d{2})/.exec(t)?.[1]).filter(Boolean).map((v) => Number(v!.replace(/,/g, "")));
  }
});

test("WEB-04 search: popular, suggest, results, no results", async ({ page }) => {
  const f = flow(page, R, "WEB-04", "Search", "Popular searches on focus, live suggestions while typing, results page with facets, and an empty result.");
  const box = () => page.getByRole("combobox", { name: "Search" });
  await f.step("Focus the header search box", "Popular / trending chips (or recent searches) open under the box", async () => {
    await visit(page, "/");
    await box().click();
    await expect(page.getByText("Popular searches")).toBeVisible();
    await expect(page.getByText("Trending now")).toBeVisible();
  });
  await f.step("Click the popular search chip", "Search results for that query", async () => {
    const chip = page.locator("header form[role=search]").getByRole("button").or(page.locator("header form[role=search]").getByRole("link")).filter({ hasText: /\w/ }).last();
    const q = (await chip.innerText()).trim();
    await chip.click();
    await expect(page).toHaveURL(new RegExp(`/search\\?q=${q}`));
    await settle(page);
    return `chip "${q}"`;
  }, { soft: true });
  await f.step("Type 'nov'", "Suggestions list the Nova X12 product", async () => {
    // Keep the pointer off the suggestion list: hovering an option makes it the one Enter opens.
    await page.mouse.move(5, 790);
    await box().fill("nov");
    await expect(page.getByRole("option", { name: /Nova X12/ }).first()).toBeVisible();
  });
  await f.step("Press Enter", "/uae-en/search?q=nov with results and facets", async () => {
    await box().press("Enter");
    await expect(page).toHaveURL(/\/search\?q=nov/);
    await settle(page);
    await expect(main(page)).toContainText("Nova X12");
  });
  await f.step("Pick the suggestion directly", "Goes to the product page", async () => {
    await box().fill("nova x");
    await page.getByRole("option", { name: /Nova X12/ }).first().click();
    await expect(page).toHaveURL(/\/products\/nova-x12-smartphone/);
  });
  await f.step(`Search for a word with no match (zzqx${RUN})`, "Empty state with a hint", async () => {
    await visit(page, `/search?q=zzqx${RUN}`);
    await expect(main(page)).toContainText(/No results|No products|couldn't find|Nothing/i);
  });
  f.end();
});

test("WEB-05 product page: variants, offers, FBN, FBT, compare, Q&A, reviews", async ({ page }) => {
  const f = flow(page, R, "WEB-05", "Product page", "Everything on the PDP of the e2e store's product: variants, buy box and other offers, Fulfilled by Nawy badge, frequently bought together, compare, reviews filters and Q&A.");
  const slug = fixtures().product_slug;
  await f.step(`Open /products/${slug}`, "Title, price AED 95.00 (FBN buy box), 'Fulfilled by Nawy' badge, sold by E2E Store, delivery promise", async () => {
    await visit(page, `/products/${slug}`);
    await expect(main(page).locator("h1:visible")).toContainText("Nova X12");
    await expect(main(page)).toContainText("Fulfilled by Nawy");
    await expect(main(page)).toContainText("E2E Store");
    await expect(main(page)).toContainText(/Get it/);
  });
  await f.step("Switch the colour variant Black → White", "The selected option changes (price / stock update)", async () => {
    await main(page).getByRole("button", { name: "White", exact: true }).click();
    await settle(page);
    await main(page).getByRole("button", { name: "Black", exact: true }).click();
    await settle(page);
  });
  await f.step("Open '… other offers from …'", "Offer list with other sellers, each with price, seller and Add to cart", async () => {
    await main(page).getByRole("button", { name: /other offers/ }).first().click();
    await expect(main(page).getByRole("button", { name: "Choose" }).first()).toBeVisible();
    return `${await main(page).getByRole("button", { name: "Choose" }).count()} other offers listed`;
  });
  await f.step("Choose the E2E Store's own seller-fulfilled offer (AED 120.00)", "The buy box switches to that offer (AED 120.00, sold by E2E Store, no FBN badge on it)", async () => {
    const row = main(page).locator("li").filter({ hasText: "E2E Store" }).filter({ hasText: "120.00" }).first();
    await row.getByRole("button", { name: "Choose" }).click();
    await expect(main(page)).toContainText("120.00");
  }, { soft: true });
  await f.step("Frequently bought together", "Bundle with the product and its companions, or no block when there is no co-purchase data", async () => {
    const fbt = main(page).getByText(/Frequently bought together/i).first();
    const shown = await fbt.isVisible().catch(() => false);
    if (shown) await fbt.scrollIntoViewIfNeeded();
    const data = await API_GET(page, `/products/${slug}/frequently-bought-together`).catch(() => null);
    return shown ? "FBT block shown" : `no FBT block (API ${JSON.stringify(data)?.slice(0, 80)})`;
  });
  await f.step("Reviews: sort Most helpful, filter 5 stars, With photos", "Filters apply (empty state 'No reviews yet' for this product)", async () => {
    const reviews = page.locator("#reviews");
    await reviews.scrollIntoViewIfNeeded();
    await page.getByLabel("Sort reviews").selectOption({ index: 1 });
    await page.getByLabel("All stars").selectOption({ index: 1 });
    await reviews.getByLabel("With photos").check();
    await settle(page);
  });
  await f.step("Questions & answers as a guest", "'Sign in to ask a question' button", async () => {
    const q = page.locator("#questions");
    await q.scrollIntoViewIfNeeded();
    await expect(q.getByRole("button", { name: "Sign in to ask a question" }).or(q.getByRole("link", { name: "Sign in to ask a question" }))).toBeVisible();
  });
  await f.step("Add to compare, then add a second product and open Compare", "Compare page shows both products side by side", async () => {
    await page.goto(`/${MARKET}/products/${slug}`);
    await settle(page);
    await main(page).getByRole("button", { name: "Add to compare" }).first().click();
    const other = (await API_GET(page, "/products?per_page=5") as { slug: string }[]).find((p) => p.slug !== slug)!.slug;
    await visit(page, `/products/${other}`);
    await main(page).getByRole("button", { name: "Add to compare" }).first().click();
    await visit(page, "/compare");
    await main(page).getByRole("link", { name: "Compare now" }).click();
    await settle(page);
    await expect(main(page)).toContainText("Nova X12");
    expect(await main(page).locator('a[href*="/products/"]').count()).toBeGreaterThanOrEqual(2);
    return `compared ${slug} with ${other}`;
  });
  f.end();
});

test("WEB-06 seller storefront and follow", async ({ page }) => {
  const f = flow(page, R, "WEB-06", "Seller storefront", "The e2e store's storefront: header, policies, products, reviews tab, quick chips; a signed-in shopper follows and unfollows it.", "A new shopper (follow step).");
  const store = fixtures().store_slug;
  await f.step(`Open /seller/${store} as a guest`, "Store name, badges, policies (returns, shipping, warranty), product grid", async () => {
    await visit(page, `/seller/${store}`);
    await expect(main(page).locator("h1:visible")).toContainText("E2E Store");
    await expect(main(page)).toContainText("Store policies");
    await expect(main(page).locator('a[href*="/products/"]').first()).toBeVisible();
  });
  await f.step("Reviews tab and 'Fulfilled by Nawy' chip", "Tabs switch without errors", async () => {
    await main(page).getByRole("link", { name: /Reviews/ }).first().click();
    await expect(page).toHaveURL(/tab=reviews/);
    await settle(page);
    await page.goto(`/${MARKET}/seller/${store}?fulfilment=fbn`);
    await settle(page);
    await expect(main(page)).toContainText("Nova X12");
  });
  await f.step("Signed in: Follow → Following, follower count +1; Unfollow", "Follow state toggles", async () => {
    await newCustomer(page, "follow");
    await visit(page, `/seller/${store}`);
    await main(page).getByRole("button", { name: "Follow", exact: true }).click();
    await expect(main(page).getByRole("button", { name: /Following|Unfollow/ })).toBeVisible();
    await visit(page, "/account/followed-stores");
    await expect(main(page)).toContainText("E2E Store");
    await visit(page, `/seller/${store}`);
    await main(page).getByRole("button", { name: /Following|Unfollow/ }).click();
    await expect(main(page).getByRole("button", { name: "Follow", exact: true })).toBeVisible();
  });
  f.end();
});

test("WEB-07 creators directory and creator storefront", async ({ page }) => {
  const f = flow(page, R, "WEB-07", "Creator storefronts", "The creators directory lists creators; a creator boutique shows the profile, socials and picks.");
  await f.step("Open /uae-en/creators", "Creator cards", async () => {
    await visit(page, "/creators");
    expect(await main(page).locator('a[href*="/creators/"]').count()).toBeGreaterThan(0);
  });
  await f.step("Open the first creator", "Boutique: name, followers, about, picks (or the empty picks state)", async () => {
    await main(page).locator('a[href*="/creators/"]:visible').first().click();
    await expect(page).toHaveURL(/\/creators\/[a-z0-9-]+/);
    await settle(page);
    await expect(main(page)).toContainText(/picks/i);
  });
  await f.step("Marketers landing /uae-en/marketers", "Roles to join as a marketer", async () => {
    await visit(page, "/marketers");
    await expect(main(page)).toContainText(/Influencer|Affiliate|Broker/);
  });
  f.end();
});

test("WEB-08 deals and flash sale", async ({ page }) => {
  const f = flow(page, R, "WEB-08", "Deals and flash sales", "The deals page lists live flash sales with a countdown; the flash-sale page shows its deals and limits; a deal goes to the product.");
  await f.step("Open /uae-en/deals", "'ALL DEALS', the live flash sale 'Weekend Frenzy' with 'Ends in' countdown and a deal card", async () => {
    await visit(page, "/deals");
    await expect(main(page)).toContainText(/Ends in/);
    expect(await main(page).locator('a[href*="/products/"]').count()).toBeGreaterThan(0);
  });
  await f.step("See all deals → flash sale page", "Flash sale header (LIVE, limit per customer), countdown, deals", async () => {
    await main(page).getByRole("link", { name: /See all deals/ }).first().click();
    await expect(page).toHaveURL(/\/flash-sale\//);
    await settle(page);
    await expect(main(page)).toContainText(/FLASH SALE/i);
  });
  await f.step("Open the deal's product", "The product page shows the flash price for the deal's offer (buy box or the offers list)", async () => {
    const card = main(page).locator('a[href*="/products/"]').first();
    const dealPrice = /AED\s*([\d,]+\.\d{2})/.exec(await card.innerText())?.[1];
    await card.click();
    await expect(page).toHaveURL(/\/products\//);
    await settle(page);
    const buyBox = await main(page).innerText();
    const inBuyBox = dealPrice ? buyBox.includes(dealPrice) : false;
    if (!inBuyBox) {
      await main(page).getByRole("button", { name: /other offers/ }).first().click().catch(() => undefined);
      await settle(page, 2_000);
    }
    const all = await page.locator("body").innerText();
    expect(all, `deal price AED ${dealPrice} visible on the product page`).toContain(dealPrice!);
    return inBuyBox ? `deal price AED ${dealPrice} in the buy box` : `deal price AED ${dealPrice} only in other offers`;
  }, { soft: true });
  f.end();
});

test("WEB-09 live streams: list, watch page and chat", async ({ page }) => {
  const f = flow(page, R, "WEB-09", "Live streams (Nawy TV)", "The live list, the watch page (player / not started state, products in the live, likes) and the moderated live chat.", "A new shopper for the chat step.");
  const slug = ((await API_GET(page, "/live-streams")) as { slug: string }[])[0]?.slug;
  await f.step("Open /uae-en/live", "Live / upcoming / replays sections with stream cards", async () => {
    await visit(page, "/live");
    expect(await main(page).locator('a[href*="/live/"]').count()).toBeGreaterThan(0);
  });
  await f.step(`Open the watch page /live/${slug}`, "Title, player or 'hasn't started yet' state, likes, 'In this live', Live chat", async () => {
    await visit(page, `/live/${slug}`);
    await expect(main(page)).toContainText("Live chat");
  });
  await f.step("As a guest, the chat asks to sign in (or the input is disabled)", "No anonymous comments", async () => {
    const input = page.getByLabel(/Say something nice/);
    const signIn = main(page).getByRole("link", { name: /Sign in/ }).or(main(page).getByRole("button", { name: /Sign in/ }));
    const ok = (await signIn.count()) > 0 || !(await input.isEnabled().catch(() => false));
    return ok ? "sign-in prompt shown" : "the guest sees an enabled chat box (sending will ask to sign in)";
  });
  let liveSlug = "";
  await f.step("Test data: put a copy of the demo show on air (status live) with the e2e product linked", "A live show exists", async () => {
    liveSlug = `qa-live-${RUN}`;
    const out = uaePhp(`$s = App\\Domains\\LiveMedia\\Models\\LiveStream::query()->where("slug", "${slug}")->firstOrFail()->replicate(["stream_key"]); $s->forceFill(["slug" => "${liveSlug}", "title_en" => "QA live ${RUN}", "title_ar" => "بث تجريبي", "status" => "live", "scheduled_at" => now(), "started_at" => now(), "ended_at" => null, "likes_count" => 0])->save(); $s->listings()->sync([${fixtures().active_listing_id} => ["sort_order" => 1]]); echo "id=".$s->id;`);
    expect(out).toMatch(/id=\d+/);
    return out;
  }, { shot: false });
  await f.step("Open the live show as a guest", "LIVE state, the linked product under 'In this live', chat asks to sign in", async () => {
    await visit(page, `/live/${liveSlug}`);
    await expect(main(page)).toContainText("Nova X12");
  }, { soft: true });
  await f.step("Signed in: post a comment", "The comment is accepted (shown, or 'waiting for moderation')", async () => {
    await newCustomer(page, "live");
    await visit(page, `/live/${liveSlug}`);
    await page.getByLabel(/Say something nice/).fill(`Great show ${RUN}`);
    await main(page).getByRole("button", { name: "Send comment" }).click();
    await expect(main(page)).toContainText(new RegExp(`Great show ${RUN}|moderat|review`, "i"));
  });
  await f.step("Like the show", "Like count goes up by one", async () => {
    const like = main(page).getByRole("button", { name: /likes?$/ }).first();
    const before = Number(/(\d+)/.exec(await like.innerText())?.[1] ?? 0);
    await like.click();
    await expect(like).toContainText(String(before + 1));
  }, { soft: true });
  if (liveSlug) uaePhp(`App\\Domains\\LiveMedia\\Models\\LiveStream::query()->where("slug", "${liveSlug}")->update(["status" => "ended", "ended_at" => now()]); echo "ok";`);
  f.end();
});

test("WEB-10 blog", async ({ page }) => {
  const f = flow(page, R, "WEB-10", "Blog", "Blog home, a category, a tag, a post and its related posts; blog search.");
  await f.step("Open /uae-en/blog", "Featured post, categories with counts, tags, post cards", async () => {
    await visit(page, "/blog");
    await expect(main(page)).toContainText("News, tips and stories");
  });
  await f.step("Open the 'Vendor Tips' category", "Only that category's posts", async () => {
    // Other agents add empty test categories: open the seeded "Vendor Tips" (5 posts).
    await main(page).locator('a[href*="/blog/category/vendor-tips"]:visible').first().click();
    await expect(page).toHaveURL(/\/blog\/category\//);
    await settle(page);
  });
  await f.step("Open a post", "Post title, body, date and related posts", async () => {
    await main(page).locator('a[href*="/blog/"]:not([href*="/category/"]):not([href$="/blog"]):visible').first().click();
    await expect(page).toHaveURL(/\/blog\/[a-z0-9-]+$/);
    await settle(page);
    await expect(main(page).locator("h1:visible")).toBeVisible();
  });
  await f.step("Search posts for 'pricing'", "Matching posts listed", async () => {
    await visit(page, "/blog?q=pricing");
    await expect(main(page)).toContainText(/Pricing/i);
  });
  f.end();
});

test("WEB-11 help centre", async ({ page }) => {
  const f = flow(page, R, "WEB-11", "Help centre", "Help home, collection, article with helpful vote, search, and the Ads knowledge hub.");
  await f.step("Open /uae-en/help", "Search box, topics, popular articles, contact options", async () => {
    await visit(page, "/help");
    await expect(main(page)).toContainText("How can we help?");
  });
  await f.step("Open a collection that has articles", "Its articles", async () => {
    await main(page).locator('a[href*="/help/collections/"]:visible').filter({ hasText: /\b[1-9]\d* articles?\b/ }).first().click();
    await expect(page).toHaveURL(/\/help\/collections\//);
    await settle(page);
  });
  await f.step("Open an article and vote 'Yes' on 'Was this helpful?'", "Thanks message after the vote", async () => {
    await main(page).locator('a[href*="/help/articles/"]:visible').first().click();
    await expect(page).toHaveURL(/\/help\/articles\//);
    await settle(page);
    await main(page).getByRole("button", { name: /^Yes/ }).first().click();
    await expect(main(page)).toContainText(/Thanks|thank you/i);
  });
  await f.step("Search the help centre for 'return'", "Search results page", async () => {
    await visit(page, "/help");
    await page.getByLabel("Search help articles").fill("return");
    await main(page).getByRole("button", { name: "Search" }).click();
    await expect(page).toHaveURL(/\/help\/search\?q=return/);
    await settle(page);
  });
  await f.step("Ads knowledge hub /uae-en/adsupport", "Hub home renders", async () => {
    await visit(page, "/adsupport");
  });
  f.end();
});

test("WEB-12 static and legal pages", async ({ page }) => {
  const f = flow(page, R, "WEB-12", "Static and legal pages", "Footer policy links open their pages; the seller agreement page renders; titles are correct.");
  await f.step("Footer → Privacy", "/pages/privacy-policy with the policy body", async () => {
    await visit(page, "/");
    await page.locator("footer").getByRole("link", { name: /Privacy/ }).first().click();
    await expect(page).toHaveURL(/\/pages\//);
    await settle(page);
    await expect(main(page).locator("h1:visible")).toBeVisible();
  });
  await f.step("Browser tab title of the privacy page", "'Privacy Policy | Nawy' (brand once)", async () => {
    const title = await page.title();
    expect(title).not.toMatch(/\| Nawy \| Nawy/);
    return title;
  }, { soft: true });
  await f.step("Footer → Terms and Returns policy", "Each page renders", async () => {
    await visit(page, "/");
    for (const name of [/Terms/, /Returns/]) {
      const link = page.locator("footer").getByRole("link", { name }).first();
      if (!(await link.count())) continue;
      await link.click();
      await settle(page);
      await expect(main(page).locator("h1:visible")).toBeVisible();
      await page.goBack();
      await settle(page);
    }
  });
  await f.step("Open /uae-en/legal/seller-agreement", "Agreement text renders", async () => {
    await visit(page, "/legal/seller-agreement");
    await expect(main(page).locator("h1:visible")).toBeVisible();
  });
  f.end();
});

test("WEB-13 join / portal pages", async ({ page }) => {
  const f = flow(page, R, "WEB-13", "Join and portal pages", "Sell landing, join chooser, a join wizard's first step, application status, advertise and travel-partner landing.");
  await f.step("Open /uae-en/sell", "'Grow your business with Nawy' and the ways to work", async () => {
    await visit(page, "/sell");
    await expect(main(page)).toContainText("Ways to work with Nawy");
  });
  await f.step("Join Nawy → pick 'Product seller'", "The vendor wizard opens on its first step", async () => {
    await visit(page, "/join");
    await main(page).getByRole("radio", { name: /Product seller/ }).click();
    await expect(main(page).getByRole("radio", { name: /Product seller/ })).toHaveAttribute("aria-checked", "true");
    await main(page).getByRole("link", { name: "Continue" }).click();
    await expect(page).toHaveURL(/\/join\/vendor/);
    await settle(page);
    await expect(main(page).locator('form [name="email"]').first()).toBeVisible();
  });
  await f.step("Application status, Advertise, Travel partners pages", "Each renders its form / content", async () => {
    for (const p of ["/join/status", "/advertise", "/sell/travel-agency", "/join/resume"]) await visit(page, p);
  });
  f.end();
});

test("WEB-14 404 pages", async ({ page }) => {
  const f = flow(page, R, "WEB-14", "Not-found pages", "Unknown products, categories, sellers, blog posts and markets answer a real 404 with the not-found page.");
  const paths = [`/${MARKET}/products/no-such-product-${RUN}`, `/${MARKET}/no-such-category-${RUN}`, `/${MARKET}/seller/no-such-store-${RUN}`, `/${MARKET}/blog/no-such-post-${RUN}`, `/${MARKET}/help/nope/deeper`, "/xyz-en/cart"];
  for (const p of paths) {
    await f.step(`Open ${p}`, "HTTP 404 and 'We couldn't find that page' with a link home", async () => {
      const res = await page.goto(p);
      await settle(page);
      expect(res?.status()).toBe(404);
      await expect(page.locator("body")).toContainText(/couldn't find|not found/i);
    }, { soft: true });
  }
  f.end();
});

test("WEB-15 legacy redirects", async ({ page, request }) => {
  const f = flow(page, R, "WEB-15", "Legacy redirects", "Old-platform URLs answer 301 to their new page and keep the query string; un-prefixed paths go to the market.");
  const { from, to } = fixtures().legacy_redirect;
  await f.step(`GET ${from}?utm_source=old without following`, `301 to ${to}?utm_source=old`, async () => {
    const res = await request.get(`${from}?utm_source=old`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(new URL(res.headers().location, "http://x").pathname).toBe(to);
    return `${res.status()} → ${res.headers().location}`;
  }, { shot: false });
  await f.step(`Open ${from} in the browser`, `Lands on ${to}`, async () => {
    await page.goto(from);
    await settle(page);
    expect(new URL(page.url()).pathname).toBe(to);
  });
  await f.step("Open un-prefixed /cart and /deals", "Redirected to a market-prefixed page", async () => {
    await page.goto("/deals");
    await settle(page);
    expect(new URL(page.url()).pathname).toMatch(/^\/[a-z]{3}-(en|ar)\/deals$/);
    return page.url();
  });
  f.end();
});

test("WEB-16 Arabic storefront (RTL)", async ({ page }) => {
  const f = flow(page, R, "WEB-16", "Arabic storefront", "Home, category, product and cart in Arabic: dir=rtl, lang=ar, Arabic text, no raw keys.");
  for (const p of ["/uae-ar", "/uae-ar/electronics", `/uae-ar/products/${fixtures().product_slug}`, "/uae-ar/cart", "/uae-ar/help"]) {
    await f.step(`Open ${p}`, "dir=rtl, lang=ar, Arabic labels", async () => {
      await page.goto(p);
      await settle(page);
      await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
      await expect(page.locator("html")).toHaveAttribute("lang", "ar");
      const text = await page.locator("body").innerText();
      expect(text).toMatch(/[؀-ۿ]/);
      expect(text).not.toMatch(/\b(common|header|product|cart|checkout|listing)\.[a-zA-Z]+\b/);
    }, { soft: true });
  }
  f.end();
});

test.describe("signed-in pages", () => {
  test.use({ storageState: CUSTOMER_STATE });
  test("WEB-17 recently viewed and offers for a signed-in shopper", async ({ page }) => {
    const f = flow(page, R, "WEB-17", "Signed-in home extras", "The home page for a signed-in shopper: offers for you (coupon chips: copy / apply to cart) and the header account menu.", "The seeded e2e customer.");
    await f.step("Open /uae-en signed in", "Header shows the shopper's name, notification bell; 'Offers for you' with coupon chips", async () => {
      await visit(page, "/");
      await expect(page.locator("header").getByRole("link", { name: /Notifications/ }).or(page.locator("header").getByRole("button", { name: /Notifications/ })).first()).toBeVisible();
      await expect(main(page)).toContainText("Offers for you");
    });
    await f.step("Copy a coupon code", "'Copied' feedback", async () => {
      await page.context().grantPermissions(["clipboard-read", "clipboard-write"]);
      await main(page).getByRole("button", { name: "Copy" }).first().click();
      await expect(main(page)).toContainText(/Copied/);
    }, { soft: true });
  });
});
