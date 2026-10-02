# OOHX Frontpage — Frontend Responsive & Layout Bug Review

**Date:** 2026-04-08
**Focus:** Dropdown/overlay bugs, responsive layout, stacking context, visual breakage

---

## 1. Executive Summary

**Overall Verdict: PASS WITH FIXES**

The CSS architecture is well-structured with proper z-index layering and responsive breakpoints. However, there are **specific layout bugs** that need fixing:
- The "Vi tri" dropdown positioning relies entirely on JavaScript with no CSS fallback
- `.search-box` has unnecessary `overflow:hidden` that could clip focus states
- Booking panel (`.bp`) is NOT hidden on mobile — obscures 40%+ of viewport
- Missing intermediate breakpoints cause awkward layouts on tablets
- No mobile filter access on listing page (sidebar hidden, no mobile filter panel)

---

## 2. Critical Layout Bugs

### BUG-1: Mega Dropdown Has No CSS `top` Fallback
**Page:** Homepage (index.blade.php)
**Element:** `.mega-drop`
**Symptom:** Dropdown positioned at `top: auto` — relies entirely on JS to set position. If JS fails or is delayed, dropdown appears at wrong location.
**CSS:** `position:fixed; top:auto; left:50%; transform:translateX(-50%); z-index:600`
**Fix:**
```css
.mega-drop {
  top: calc(var(--hdr) + 120px); /* fallback below search bar */
}
```

### BUG-2: Booking Panel Visible on Mobile — Blocks Content
**Page:** Detail page (detail.blade.php)
**Element:** `.bp` (booking panel)
**Symptom:** `position:sticky; top:calc(var(--hdr)+16px)` makes panel stick on ALL breakpoints. On mobile, panel occupies 40%+ of viewport. `.mcta` (mobile CTA bar) exists but `.bp` is never hidden.
**Fix:**
```css
@media(max-width:1023px) {
  .bp { display: none; }
}
```

### BUG-3: Sidebar Inaccessible on Tablets (768-1023px)
**Page:** Listing page (listing.blade.php)
**Element:** `.sidebar`
**Symptom:** `display:none` until 1024px. Tablet users in portrait mode cannot access filters at all. Mobile filter button (`.mob-filter`) exists in CSS but may not be rendered in the Blade view.
**Fix:** Ensure `.mob-filter` button is in listing.blade.php, or add tablet sidebar as slide-out drawer.

---

## 3. Medium Layout Issues

### M-1: `.search-box` Has Unnecessary `overflow:hidden`
**Element:** `.search-box` (line 254 of frontpage.css)
**Risk:** Clips focus rings, tooltips, or any child that escapes bounds
**Fix:** Remove `overflow:hidden` or change to `overflow:visible`

### M-2: City Grid Jumps from 2 Columns to 4 with No Intermediate
**Element:** `.city-grid`
**CSS:** `1fr 1fr` → `4 cols @768px`. No 3-column option at 600px.
**Fix:**
```css
@media(min-width:600px) { .city-grid { grid-template-columns: repeat(3, 1fr); } }
```

### M-3: Header and Bottom Nav Both z-index:400
**Elements:** `.hdr` (sticky) and `.bnav` (fixed)
**Risk:** Ambiguous stacking on mobile if both visible simultaneously.
**Fix:** `.hdr { z-index: 401; }` — header always above bottom nav.

### M-4: Specs Grid Border Logic Breaks at 3 Columns
**Element:** `.specs-grid` in detail page
**CSS:** `nth-child(2n)` removes right border for 2-col, but at 640px switches to 3-col — borders misalign.
**Fix:** Add `@media(min-width:640px)` nth-child rules for 3-column layout (already partially addressed in CSS but incomplete).

### M-5: Screen Card Photo Fixed Height 180px
**Element:** `.inv-photo` (listing/homepage cards)
**CSS:** `height:180px` — not responsive. On 2-column mobile (cards ~156px wide), creates 1.15:1 ratio.
**Fix:**
```css
.ic-photo { height: auto; aspect-ratio: 16/10; }
```

---

## 4. Minor UI Cleanup

- `.hero::before` gradient extends with `width:120%` — could cause subtle horizontal scroll on some mobile browsers
- `.hb-url` has `max-width:340px` — could clip on very narrow devices
- Multiple fixed bottom elements (`.bnav`, `.fcart`, `.mob-filter`, `.mcta`) stack at bottom — verify they're mutually exclusive per page
- `.map-popup` on mobile is `width:calc(100%-32px)` — content cramped at 320px width
- Owner card `.oc-name-ver` (verified badge) sits inside truncated `.oc-name` container — badge may get clipped

---

## 5. Breakpoint Findings

### Mobile (320px–767px)

| Finding | Severity | Element |
|---------|----------|---------|
| Booking panel blocks 40%+ of viewport | CRITICAL | `.bp` |
| Search input cramped on <360px phones | MEDIUM | `.search-box` |
| Category grid 2-col gap too tight at 360px | LOW | `.cat-grid` |
| Filter chips may overflow at <320px | LOW | `.fchips` |
| Type grid 2 columns squeezed at 480-519px | LOW | `.type-grid` |
| Inventory card photo fixed 180px — disproportionate on narrow cards | MEDIUM | `.inv-photo` / `.ic-photo` |
| Stats in owner-card-mini compress without flex-wrap | LOW | `.oc-stats` |

### Tablet (768px–1023px)

| Finding | Severity | Element |
|---------|----------|---------|
| Sidebar completely hidden — no filter access | HIGH | `.sidebar` |
| City grid jumps to 4 columns — may be too wide | MEDIUM | `.city-grid` |
| Listing still 3-column grid when 4 would fit at 1024px | LOW | `.inv-grid` |
| Owner card grid stays 2-col when 3 would fit at 768px | LOW | `.owners-layout` |

### Desktop (1024px+)

| Finding | Severity | Element |
|---------|----------|---------|
| Header/nav z-index same as bottom nav (400) | LOW | `.hdr`, `.bnav` |
| Specs grid border mismatch at 3-column breakpoint | MEDIUM | `.specs-grid` |
| No issues with dropdown positioning at desktop widths | OK | `.mega-drop` |

---

## 6. Dropdown / Overlay Findings

### Dropdown: "Vị trí" / "Loại biển" Mega Dropdown

**Page:** Homepage
**Symptom:** Dropdown may appear hidden behind content or pushed downward if JS positioning fails.

**Likely Cause Analysis:**

1. `.mega-drop` uses `position:fixed; top:auto` — position depends entirely on JavaScript. **No CSS fallback position.**

2. `.mega-drop` has `overflow:hidden` — internal content cannot escape the panel, which is correct, but `.loc-cols` grid may need scroll if many provinces.

3. `.search-wrap` is `position:relative` — this does NOT affect the `position:fixed` dropdown (fixed escapes relative parents). **This is correct.**

4. `.hero` section has `overflow:visible` — **correct**, allows content to escape.

5. `.search-box` has `overflow:hidden` — this is a sibling of `.mega-drop`, NOT a parent. **Not the cause of clipping.**

6. The dropdown animation uses `transform:translateX(-50%) translateY(-8px)` in keyframe — **transform creates stacking context but this is fine since z-index:600 is highest.**

**Root Cause:** The dropdown positioning is JS-dependent with no CSS fallback. If `top` is not set by JS, the dropdown renders at `top:auto` which places it at its static position in the document flow — potentially behind other content.

**Fix Strategy:**
```css
.mega-drop {
  /* Add fallback positioning */
  top: calc(var(--hdr) + 130px);
  max-height: calc(100vh - var(--hdr) - 160px);
  overflow-y: auto; /* allow scroll for long content */
}
```

Also verify the JavaScript `toggleDrop()` function sets `top` correctly:
```javascript
function toggleDrop(type) {
  var btn = document.getElementById('sf-' + type);
  var drop = document.getElementById('drop-' + type);
  var rect = btn.getBoundingClientRect();
  drop.style.top = (rect.bottom + 8) + 'px'; // position below button
  drop.classList.toggle('open');
}
```

### Z-Index Layer Map (Complete)

| Z-Index | Element | Position | Notes |
|---------|---------|----------|-------|
| 600 | `.mega-drop` | fixed | Highest — dropdowns |
| 590 | `.drop-backdrop` | fixed | Behind dropdowns |
| 400 | `.hdr` | sticky | Header — should be 401 |
| 400 | `.bnav` | fixed | Mobile bottom nav |
| 350 | `.fcart` | fixed | Floating cart |
| 200 | `.tabs-bar` | sticky | Detail page tabs |
| 200 | `.mob-filter` | fixed | Mobile filter button |
| 200 | `.mcta` | fixed | Mobile CTA bar |
| 200 | `.panel-toggle` | fixed | Map panel toggle |
| 10 | `.map-controls` | absolute | Map zoom controls |
| 10 | `.map-toolbar` | absolute | Map city tabs |

**Verdict:** Z-index hierarchy is well-structured. Dropdown at 600 is correctly above all other layers. The issue is positioning (`top`), not stacking.

---

## 7. Layout Bug List

### Bug 1
- **Page:** Detail
- **Breakpoint:** Mobile (<1024px)
- **Element:** `.bp` (booking panel)
- **Symptom:** Sticky panel blocks 40%+ of viewport on scroll
- **Likely cause:** No `display:none` rule for mobile
- **Fix:** `@media(max-width:1023px) { .bp { display:none } }`

### Bug 2
- **Page:** Homepage
- **Breakpoint:** All
- **Element:** `.mega-drop`
- **Symptom:** Dropdown may appear at wrong position if JS fails
- **Likely cause:** `top:auto` with no CSS fallback
- **Fix:** Add `top:calc(var(--hdr)+130px)` as default, let JS override

### Bug 3
- **Page:** Listing
- **Breakpoint:** 768-1023px
- **Element:** `.sidebar`
- **Symptom:** Filters completely inaccessible on tablets
- **Likely cause:** `display:none` until 1024px, no mobile filter UI rendered
- **Fix:** Add `.mob-filter` button to listing.blade.php

### Bug 4
- **Page:** Homepage
- **Breakpoint:** 600-767px
- **Element:** `.city-grid`
- **Symptom:** Only 2 columns, cards too wide on tablets
- **Likely cause:** No 600px breakpoint, jumps directly to 4 cols at 768px
- **Fix:** Add `@media(min-width:600px){.city-grid{grid-template-columns:repeat(3,1fr)}}`

### Bug 5
- **Page:** Detail
- **Breakpoint:** 640px+
- **Element:** `.specs-grid`
- **Symptom:** Border logic misaligned at 3-column layout
- **Likely cause:** `nth-child(2n)` rule for 2-col doesn't reset for 3-col
- **Fix:** Override nth-child rules in 640px media query

---

## 8. File-by-File Review

### frontpage.css
- **Good:** Well-structured breakpoints, proper z-index hierarchy, good overflow containment on images/cards
- **Wrong:** `.search-box overflow:hidden` unnecessary, `.bp` not hidden on mobile, missing 600px breakpoint for city-grid
- **Change:** Add 5 CSS rules (see Fix Priority Plan)

### index.blade.php
- **Good:** Dynamic data rendering, @foreach loops with partials, search filter structure
- **Wrong:** Mega dropdown relies on JS for `top` position with no fallback
- **Change:** Add CSS fallback `top` value

### detail.blade.php
- **Good:** Full screen data rendered, specs grid, booking panel
- **Wrong:** `.bp` visible on mobile — blocks content
- **Change:** Hide `.bp` on mobile via CSS

### listing.blade.php
- **Good:** Pagination, filter chips, empty state
- **Wrong:** No mobile filter access button in template, sidebar hidden on tablets
- **Change:** Add `.mob-filter` button if missing

### map.blade.php
- **Good:** Panel toggle, pin rendering, responsive popup
- **Wrong:** Popup cramped at 320px
- **Change:** Minor — acceptable for MVP

### partials/screen-card.blade.php
- **Good:** Null-safe accessors, price formatting, fallback photo
- **Wrong:** Fixed 180px photo height
- **Change:** Consider `aspect-ratio` instead of fixed height

---

## 9. Fix Priority Plan

### Fix Now (Critical UX bugs)

1. **Hide `.bp` on mobile** — add to frontpage.css:
```css
@media(max-width:1023px) { .bp { display:none } }
```

2. **Add dropdown CSS fallback** — add to frontpage.css:
```css
.mega-drop { top: calc(var(--hdr) + 130px); max-height: calc(100vh - var(--hdr) - 160px); overflow-y: auto; }
```

3. **Remove `.search-box overflow:hidden`**:
```css
.search-box { overflow: visible; }
```

### Fix Next (This sprint)

4. **Add 600px city grid breakpoint**
5. **Separate header z-index** (401 vs 400)
6. **Ensure mobile filter button exists** in listing.blade.php
7. **Fix specs-grid border logic** for 3-column layout

### Can Postpone

8. Screen card photo `aspect-ratio` instead of fixed height
9. Owner card stats flex-wrap
10. Map popup width tweak for 320px
11. Additional tablet breakpoints for owner-grid and inventory-grid

---

## 10. Final Recommendation

**Merge after layout fixes**

The 3 "Fix Now" items take ~10 minutes total and resolve the most visible bugs (blocking booking panel on mobile, dropdown positioning, search-box overflow). After these fixes, the frontpage is production-ready for the current data set. The "Fix Next" items should follow in the same sprint.
