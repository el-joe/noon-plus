# Figma team guide (for every designer/agent writing to the Nawy file)

File key: `d2MhBJmRddMrMSHfjJY8Y8`. Load the figma-use skill first and call `create_session` with this file key before `use_figma`.
Node IDs of everything that exists: `docs/design/figma-state.json`. Coverage: `docs/design/design-coverage.md`.

## Rules
- Original Nawy design only. No Noon logos, artwork, brand names in UI copy, or assets.
- Build only on **your assigned page** (or the y-band you were given). Never move or edit other frames.
- Each `use_figma` script is atomic: if it throws, nothing is saved. Keep scripts under ~40k characters. Put a skip-guard at the top (`if (page.children.find(n=>n.name.startsWith('…'))) return {skipped:true}`).
- `appendChild` returns undefined — create, append, *then* set `layoutSizingHorizontal='FILL'`.
- Set FILL/HUG only after the node is inside an auto-layout parent. `layoutPositioning='ABSOLUTE'` only after append.
- Load fonts first: Inter Regular/Medium/Semi Bold/Bold/Extra Bold (Arabic: Cairo Regular/Medium/SemiBold/Bold).
- Use variables and text styles, not raw colours: color variables `color/bg/{page,surface,subtle,inverse,brand,brand-hover,brand-subtle,success-subtle,danger-subtle,warning-subtle,info-subtle,success,danger,sidebar,sidebar-active}`, `color/text/{primary,secondary,muted,inverse,brand,link,success,danger,info}`, `color/border/{default,brand}`. Text styles `en/{display,h1,h2,h3,h4,body-lg,body,body-medium,body-sm,label,caption,price-lg,price}` and `ar/*` (Cairo).
- Image placeholders: frames named `img/...` filled with `color/bg/subtle`. No generated photos unless asked.
- After building, take `get_screenshot` of each new frame, look at it, fix clipping/overflow, then report the new node IDs.
- Money in examples uses the right currency per country (AED, OMR 3 decimals, SAR, EGP, KWD/BHD/JOD 3 decimals, QAR). Times in panels: country local time + "Oman" label for admin.

## Helper snippet (paste at the top of scripts)
```js
for (const s of ['Regular','Medium','Semi Bold','Bold','Extra Bold']) await figma.loadFontAsync({family:'Inter',style:s});
const vars=await figma.variables.getLocalVariablesAsync(); const V=n=>vars.find(x=>x.name===n);
const ts=await figma.getLocalTextStylesAsync(); const TS=n=>ts.find(s=>s.name===n).id;
const paint=vn=>figma.variables.setBoundVariableForPaint({type:'SOLID',color:{r:0,g:0,b:0}},'color',V(vn));
const fill=(n,vn)=>{ n.fills=vn?[paint(vn)]:[]; };
const stroke=(n,vn,w=1)=>{ n.strokes=[paint(vn)]; n.strokeWeight=w; n.strokeAlign='INSIDE'; };
const text=(c,style,color)=>{ const t=figma.createText(); t.textStyleId=TS(style); t.characters=c; fill(t,color); return t; };
const wrap=(p,t)=>{ p.appendChild(t); t.layoutSizingHorizontal='FILL'; t.textAutoResize='HEIGHT'; return t; };
const auto=(dir,props={})=>{ const f=figma.createAutoLayout(dir,props); f.fills=[]; return f; };
const row=(p,gap=12)=>{ const r=auto('HORIZONTAL',{itemSpacing:gap}); p.appendChild(r); r.layoutSizingHorizontal='FILL'; return r; };
const card=(name,gap=12)=>{ const c=auto('VERTICAL',{name,itemSpacing:gap}); fill(c,'color/bg/surface'); c.cornerRadius=12; stroke(c,'color/border/default'); c.paddingLeft=c.paddingRight=c.paddingTop=c.paddingBottom=20; return c; };
const C=async id=>await figma.getNodeByIdAsync(id);
const setP=(i,pre,v)=>{ const k=Object.keys(i.componentProperties).find(k=>k.split('#')[0]===pre); if(k) i.setProperties({[k]:v}); };
const BTN=await C('4:152'); const btn=(style,size,label)=>{ const b=BTN.children.find(c=>c.name===`Style=${style}, Size=${size}, State=Default`).createInstance(); setP(b,'Label',label); setP(b,'Show icon',false); return b; }; // Style: Primary|Secondary|Outline|Ghost, Size: SM|MD|LG
```

## Key components (IDs)
- Core: Button `4:152` (set), Input `4:185` (variant `State=Default`; props Label, Value, Helper, Show helper), Select `4:213` (Label, Value), Checkbox `6:77` (`Checked=On|Off`, Label), Radio `6:84` (`Selected=On|Off`, Label), Switch `6:93`, Badge `6:112` (`Tone=Neutral|Brand|Success|Warning|Danger|Info`, Label), Alert `6:156` (`Tone=Info|Warning|…`, Title, Message), Tab `6:165`, Avatar `6:172` (`Size=SM|MD|LG`, Initials), Breadcrumb `6:178`, Modal `11:375`, Empty state `11:405`, Wizard step `11:436` (`State=Done|Current|Upcoming`, Title), Payment method option `11:470` (`State=Selected|Default|Disabled`; Name, Description, Recommended).
- Panel kit: Sidebar item `9:20` (`State=Default|Active`; Label, Count, Show count), Page header `9:231` (Title, Subtitle), Stat card `9:301` (`Trend=Up|Down`; Label, Value, Delta), Data table `11:147`, Date-time input `45:324`, Date-time display `45:325`, State timeline `77:622`, Contract signature box `77:650`, Budget meter `77:664`, Fee quote card `77:682`, Referral card `77:700`, Sample tracker `77:724`, Map panel `77:746`.
- Panel shells (clone, then fill `Content slot`; set slot `layoutSizingVertical='HUG'`): Admin v2 `74:1394`, Seller Center `12:410`, Creator Studio `12:687`, Marketplace `12:938`, Broker `12:1149`, Travel Partner `12:1392`, Shipping `12:1642`, Ops `12:1891`, Delivery agent `74:1909`. Example filled layouts: `90:1821` (vendor dashboard), `90:2785` (campaign detail).
- Web: Web header `17:55`, Category nav `17:94`, Web footer `17:137` (booleans: Show Thawani (Oman only), Show Tabby, Show Tamara — UAE and Saudi Arabia; no PayPal, O4), Product card `16:119` (`Type=Default|Sponsored|Deal`; Title, Price, Was price, Discount, Show discount, Badge, Show badge), Product card compact `29:130`, Block header `29:175` (Title), Classified ad card `53:171`, Creator card `53:172`, Seller box `53:200`, Safety tips `53:235`, Shoppable video tile `53:252`, Rating `16:2`, Countdown `16:134`, Coupon chip `16:144`. Page-builder blocks on page `37:2` (e.g. hero_carousel `37:12`, product_carousel `39:436`, product_grid `39:1344`, mega_deals `39:1156`).
- Portal: Portal header `72:2` (component). Portal frames are 1440 wide, header + Main (padding 48) + footer instance.
- App (375×812): Status bar `80:3`, Top bar `80:7` (Title, Action), Tab bar set `80:91` (`Active=Home|Categories|Deals|Account|Cart`), Delivery tab bar set on page `80:2`, Ops tab bar set `163:16085` (`Active=1..5`; Tab N label, Tab N icon).
