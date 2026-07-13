# «3 метра» — speed-dating landing page (Rostov-on-Don)

Single-page static site (no build step): `index.html` + `styles.css` + `script.js` + `media/`.
Page language is Russian. All UI copy uses «вы», Russian typographic quotes «», and em dashes.

## The service

Offline speed-dating evenings at bar-restaurant GasGas — on the page always
«бар-ресторан GasGas», never plain «бар» or the old spelling «Gazgaz» (closed for the
event; only participants,
host, and the team). 10–15 pairs per evening, ~5-minute one-on-one conversations with
rotation. Guests privately mark sympathies; only **mutual** matches are revealed, and a
matched pair receives a first-date gift certificate (workshop / escape room / cinema).

Two age groups, each with its own evenings:

| Group | Men | Women | Evenings |
|---|---|---|---|
| Основная группа | 22–33 | 23–35 | Fridays (Jul 17, Jul 24) |
| Старшая группа | 33–55 | 33–50 | Saturdays (Jul 18, Jul 25) |

All evenings start 19:00. **Prices: men 2 000 ₽, women 2 300 ₽ — full payment online at
registration** (no deposits/prepayments; this wording was deliberately removed sitewide).
Every ticket includes a 700 ₽ bar credit — never call it a «депозит».

### Policies

- Shown on the page: balanced 50/50 composition, assembled manually; if a noticeable
  imbalance happens — refund or transfer, guest's choice. Photo/video published only
  with the guest's consent (FAQ + included card).
- NO LONGER shown anywhere (removed with the July 2026 FAQ rework; owner never
  explicitly confirmed them — do not re-add without confirmation): 48-hour full-refund
  window; no-mutual-matches → free seat at the next evening (once, within 60 days).
  Refund/cancellation terms now live only in future legal/offer documents.

## Audience & positioning

Both genders book through the page, but copy is **male-leaning** (men are the harder
side to fill). The three male objections the page answers: "will there be enough women"
(parity), "will I be publicly rejected" (private sympathies), "is it worth the money".
Value proposition section: «Не сайт знакомств, не свидание вслепую и не шумная вечеринка».
Do NOT compare with dating apps (explicitly removed at owner's request) and do not
address men exclusively — the booking form has a gender toggle (male preselected).

## Hard content rules (owner-mandated, do not regress)

- **Honesty:** no fake scarcity, no numeric seat counters, no invented testimonials or
  statistics. Availability is a hand-set status (`AVAILABILITY` in `script.js`:
  open/few/closed) rendered as «Места есть» etc.
- Bookings are confirmed **only after successful payment** — pre-payment screen says
  «Данные заполнены», never «Бронь подтверждена».
- No Instagram anywhere (removed with its Meta disclaimer). Contact methods: Телефон,
  Telegram, MAX.
- No small-caps eyebrow labels above headings — sections lead with serif h2 directly.
- The logo is the owner's exact artwork (`media/logo.jpg`, favicon derived from it) —
  never redraw or restyle it.
- Consent checkbox (152-ФЗ) is required in the form; keep it.
- **No trailing period at the end of any copy block** (paragraphs, list items, captions,
  modal texts) — internal sentence periods stay.
- Never call the format «быстрые свидания»/speed dating on the page; describe the
  mechanics instead (короткие разговоры один на один, ротация каждые пять минут) with a
  calm, modern tone — it must not read as a format for the desperate.

## Design system

Dark cinematic "gentleman's bar": warm charcoal background (`--bg #100d0a`), brass/amber
accent for CTAs and highlights (`--brass #dfa146`), the crimson/red family appears only
in the logo, video glow, and rose group pill. Fonts: **Cormorant** (600, serif — display
and headings, italic for accent words in `em`) + **Golos Text** (body). Both load from
Google Fonts with Cyrillic. Buttons are pill-shaped; primary = brass fill with dark text.
Cards: `--bg-elev` surfaces, 1px hairline borders, 16–26px radii; featured bands get a
brass border + radial glow + soft brass shadow. Scroll-reveal via IntersectionObserver
(`.reveal` → `.is-in`), CSS marquee ticker (two identical halves for a seamless loop),
`prefers-reduced-motion` respected. Mobile-first; booking modal becomes a bottom sheet.

Mobile (≤700px) specifics — owner-requested, do not regress:
- **No sticky bottom CTA bar** (removed; the fixed nav CTA is the persistent entry point).
- Hero video is a full-bleed section **background** with a dark gradient overlay
  (`.hero__media::after`), copy bottom-aligned in a 100svh hero; the date badge is hidden.
- Gallery is a compact 2-column grid (3/4 cells); real photos/videos open in a
  **lightbox** (`#lightbox`, JS in script.js) — placeholders stay non-clickable.
- Reviews are a horizontal scroll-snap carousel.
- Steps show the number beside the title (2-col grid) to halve their height.
- «Всё, чтобы вечер прошёл легко» (`.included__*`) must keep **simple, widely
  supported CSS** — no `min()` inside `minmax()` etc.; it previously broke on iOS Safari
  (cards lost borders/structure).
- **No `auto-fit, minmax(NNNpx, 1fr)` grids with fixed px minimums** — on narrow
  phones (~320–360px) the track overflows the container, widens the layout viewport and
  mobile in-app browsers scale the page down, eating the side margins. Use explicit
  media-query column switches (1 col → 2 cols) instead. `html` also keeps
  `overflow-x: hidden` as a safety net.

## Page order

hero (portrait video) → next (2 upcoming-evening cards) → ticker → concept/value prop
(#why) → steps 01–04 (#format) → gallery mosaic → schedule, 2 group columns with photos
and event cards (#dates) → gift band → «Всё, чтобы вечер прошёл легко» (#price) →
reviews (chat screenshots) → founder story + photo → venue + Yandex map → FAQ (#faq) →
final CTA band → footer. Reference layouts came from
`/Users/sprudnikov99/git/landing-factory/3metra_v2/index.html`.

## script.js config (top of file)

- `PAYMENT_LINKS` — per-event payment URLs, currently empty; pay button falls back to a
  "we'll contact you" note. NB: no deposit anymore, so links must charge the full price,
  which differs by gender (2 000/2 300 ₽) — per-event links alone are insufficient;
  restructure when the payment provider is known.
- `BOOKING_ENDPOINT` — where the form POSTs JSON (empty = submissions go nowhere).
- `AVAILABILITY` — hand-maintained per-event status.
- `TICKET_PRICES` — drives dynamic price on submit/pay buttons by selected gender.
- Booking payload: event, name, age, gender, method, contact, submittedAt.

## Pending before launch (clearly marked placeholders in the page)

payment links + booking endpoint; real contacts (phone/Telegram/MAX) and ИП requisites;
organizer names/bio (photo is in place: `media/founders.jpg`);
offerta placeholders: §7.1 and §11.1 contain «УКАЗАТЬ ССЫЛКУ ИЛИ ЛОГИН / АДРЕС» for
cancellation/claims contacts (edit offerta.txt and regenerate, or edit offerta.html);
button-name mismatch: soglasie.txt/offerta reference the button «Оставить заявку»,
but the form's submit button says «Перейти к оплате» (the form's legal note follows the
real button) — rename one side before launch.

Legal pages: `policy.html` + `offerta.html` + `soglasie.html` (generated from the
matching `.txt` sources, styled via `.doc` block in styles.css, linked from the footer;
the booking form's legal note links soglasie.html + policy.html).

NB: review screenshots (`media/review-{dmitry,alina,sergey}.jpg`) are owner-staged
mock-ups, not real guest messages — swap for real ones after the first evenings.

## Dev & verification notes

- Preview: `.claude/launch.json` runs `python3 -m http.server 4519` (name: "site").
- The in-app browser's screenshot capture returns black/stale frames at deep scroll
  offsets. Workarounds: `document.body.style.transform = translateY(...)` to bring a
  section into the top viewport (note: this breaks `position: fixed` children), or
  temporarily `display:none` the other sections; always force reveals first
  (`document.querySelectorAll('.reveal').forEach(el => el.classList.add('is-in'))`).
- Verify the booking flow after changes: open modal → gender toggle changes button
  price → validation (name/age 18–99/contact/consent) → «Данные заполнены» → pay button.
- Weekday/date facts must be real: current dates are July 2026 (17/18/24/25).
